import { useEffect, useRef, useState } from 'react';
import { CameraOff, LoaderCircle, ScanFace } from 'lucide-react';
import { Button } from '@/Components/ui';
import { cn } from '@/lib/utils';

/*
 * The face is read here, on the employee's own device: face-api turns a camera
 * frame into a 128-number template, and only that template and one small
 * snapshot are sent to the server. The models are served from our own build
 * (`/build/face-models`), so no image or request goes to a third party.
 *
 * face-api and its TensorFlow runtime are about 1.3 MB, so they are imported
 * when the camera first opens rather than with the page.
 */
const MODEL_URL = '/build/face-models';

let loading = null;

function loadFaceApi() {
    if (!loading) {
        loading = (async () => {
            const faceapi = await import('@vladmandic/face-api');
            await faceapi.tf.ready();
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);

            return faceapi;
        })().catch((error) => {
            loading = null;
            throw error;
        });
    }

    return loading;
}

/** Eye aspect ratio: how open an eye is, from its six landmark points. */
function eyeOpenness(points) {
    const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const width = distance(points[0], points[3]);

    return width === 0
        ? 0
        : (distance(points[1], points[5]) + distance(points[2], points[4])) / (2 * width);
}

function snapshotOf(video) {
    const width = 480;
    const height = Math.round((video.videoHeight / video.videoWidth) * width) || 360;
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(video, 0, 0, width, height);

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
}

function cameraError(error) {
    if (!window.isSecureContext) {
        return 'The camera only works over a secure (https) connection.';
    }

    switch (error?.name) {
        case 'NotAllowedError':
            return 'Camera access was blocked. Allow the camera for this site in your browser settings, then try again.';
        case 'NotFoundError':
            return 'No camera was found on this device.';
        case 'NotReadableError':
            return 'The camera is being used by another app. Close it and try again.';
        default:
            return 'The camera could not be started on this device.';
    }
}

/**
 * Opens the camera and reads one face.
 *
 * `verify` (the time clock) asks for a blink first when `requireBlink` is on,
 * then hands back one template. `enroll` takes `samples` templates a moment
 * apart, so the enrolled face covers small changes of angle and expression.
 *
 * Calls `onCapture({ descriptor | descriptors, snapshot })`.
 */
export default function FaceCamera({
    mode = 'verify',
    samples = 3,
    requireBlink = true,
    onCapture,
}) {
    const videoRef = useRef(null);
    const [phase, setPhase] = useState('loading');
    const [prompt, setPrompt] = useState('Loading face recognition…');
    const [error, setError] = useState(null);
    const [taken, setTaken] = useState(0);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        let stream = null;
        let timer = null;
        let stopped = false;

        const stop = () => {
            stopped = true;
            clearTimeout(timer);
            stream?.getTracks().forEach((track) => track.stop());
        };

        (async () => {
            setError(null);
            setTaken(0);
            setPhase('loading');
            setPrompt('Loading face recognition…');

            let faceapi;

            try {
                faceapi = await loadFaceApi();
            } catch {
                setPhase('error');
                setError(
                    'Face recognition could not be loaded. Check the connection and try again.',
                );
                return;
            }

            if (stopped) return;
            setPrompt('Starting the camera…');

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user',
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                    },
                    audio: false,
                });
            } catch (cameraFailure) {
                setPhase('error');
                setError(cameraError(cameraFailure));
                return;
            }

            if (stopped) return stop();

            const video = videoRef.current;
            video.srcObject = stream;
            await video.play().catch(() => {});
            setPhase('scanning');

            const options = new faceapi.TinyFaceDetectorOptions({
                inputSize: 320,
                scoreThreshold: 0.5,
            });
            const descriptors = [];
            let baseline = null;
            let eyesClosed = false;
            let blinked = !(mode === 'verify' && requireBlink);
            let lastSample = 0;

            const tick = async () => {
                if (stopped) return;

                const faces = await faceapi.detectAllFaces(video, options).withFaceLandmarks();

                if (stopped) return;

                if (faces.length === 0) {
                    setPrompt('Look at the camera — no face found yet.');
                } else if (faces.length > 1) {
                    setPrompt('Only one face, please. Ask others to step out of view.');
                } else if (faces[0].detection.box.width < video.videoWidth * 0.22) {
                    setPrompt('Come a little closer to the camera.');
                } else if (!blinked) {
                    const landmarks = faces[0].landmarks;
                    const openness =
                        (eyeOpenness(landmarks.getLeftEye()) +
                            eyeOpenness(landmarks.getRightEye())) /
                        2;

                    // The open-eye level is learned from this face, because a
                    // fixed threshold reads narrow eyes as permanently shut.
                    baseline =
                        baseline === null ? openness : Math.max(baseline * 0.97, openness);

                    if (openness < baseline * 0.72) {
                        eyesClosed = true;
                    } else if (eyesClosed && openness > baseline * 0.85) {
                        blinked = true;
                    }

                    setPrompt('Now blink once, slowly.');
                } else {
                    const now = Date.now();

                    if (mode === 'verify' || now - lastSample > 600) {
                        const reading = await faceapi
                            .detectSingleFace(video, options)
                            .withFaceLandmarks()
                            .withFaceDescriptor();

                        if (stopped) return;

                        if (reading) {
                            lastSample = now;
                            descriptors.push(Array.from(reading.descriptor));
                            setTaken(descriptors.length);

                            const done = mode === 'verify' || descriptors.length >= samples;

                            if (done) {
                                setPhase('captured');
                                setPrompt('Got it.');
                                const snapshot = await snapshotOf(video);
                                stop();

                                onCapture(
                                    mode === 'verify'
                                        ? { descriptor: descriptors[0], snapshot }
                                        : { descriptors, snapshot },
                                );

                                return;
                            }
                        }
                    }

                    setPrompt(
                        mode === 'verify'
                            ? 'Hold still…'
                            : `Hold still… reading ${descriptors.length + 1} of ${samples}`,
                    );
                }

                timer = setTimeout(tick, 80);
            };

            tick();
        })();

        return stop;
        // `attempt` restarts the camera after an error.
    }, [attempt]);

    return (
        <div className="space-y-3">
            <div className="relative mx-auto aspect-[4/3] w-full max-w-md overflow-hidden rounded-xl bg-foreground/90">
                <video
                    ref={videoRef}
                    playsInline
                    muted
                    className={cn(
                        'h-full w-full -scale-x-100 object-cover',
                        phase === 'scanning' || phase === 'captured'
                            ? 'opacity-100'
                            : 'opacity-0',
                    )}
                />

                {phase === 'scanning' && (
                    <div
                        className="pointer-events-none absolute left-1/2 top-1/2 h-3/4 w-1/2 -translate-x-1/2 -translate-y-1/2 rounded-[50%] border-2 border-dashed border-card/70"
                        aria-hidden="true"
                    />
                )}

                {phase === 'loading' && (
                    <div className="absolute inset-0 grid place-items-center text-card">
                        <LoaderCircle className="h-8 w-8 animate-spin" aria-hidden="true" />
                    </div>
                )}

                {phase === 'error' && (
                    <div className="absolute inset-0 grid place-items-center p-6 text-center text-card">
                        <div>
                            <CameraOff className="mx-auto h-8 w-8" aria-hidden="true" />
                            <p className="mt-2 text-sm">{error}</p>
                        </div>
                    </div>
                )}
            </div>

            {phase !== 'error' ? (
                <p
                    className="flex items-center justify-center gap-2 text-center text-sm font-medium text-foreground"
                    aria-live="polite"
                >
                    <ScanFace className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                    {prompt}
                    {mode === 'enroll' && taken > 0 && phase === 'scanning' && (
                        <span className="text-muted-foreground">
                            ({taken}/{samples})
                        </span>
                    )}
                </p>
            ) : (
                <div className="flex justify-center">
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => setAttempt((n) => n + 1)}
                    >
                        Try again
                    </Button>
                </div>
            )}
        </div>
    );
}
