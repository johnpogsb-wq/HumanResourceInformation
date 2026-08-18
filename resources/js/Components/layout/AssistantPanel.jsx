import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Send, Sparkles, X } from 'lucide-react';
import { Button } from '@/Components/ui';

/**
 * Ask a question, get an answer from this company's own records.
 *
 * Deliberately one question at a time rather than a running chat: each answer
 * is a lookup against live data, and a transcript invites follow-ups like
 * "and last month?" that read as context the server does not keep.
 */
export default function AssistantPanel() {
    const { aiEnabled } = usePage().props;

    const [open, setOpen] = useState(false);
    const [question, setQuestion] = useState('');
    const [pending, setPending] = useState(false);
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const inputRef = useRef(null);

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    if (!aiEnabled) return null;

    const submit = async (event) => {
        event.preventDefault();
        if (!question.trim() || pending) return;

        setPending(true);
        setError(null);
        setResult(null);

        try {
            const response = await fetch('/hr/assistant', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    ),
                },
                body: JSON.stringify({ question }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.message ?? 'That did not work.');
            } else {
                setResult(data);
            }
        } catch {
            setError('Could not reach the assistant.');
        } finally {
            setPending(false);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Ask about your HR data"
                title="Ask about your HR data"
                className="grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
            >
                <Sparkles className="h-4.5 w-4.5" aria-hidden="true" />
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-50 flex items-start justify-center bg-foreground/20 p-4 pt-24"
                    onClick={() => setOpen(false)}
                >
                    <div
                        className="w-full max-w-2xl overflow-hidden rounded-lg border border-border bg-card shadow-lg"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                            <Sparkles className="h-4 w-4 text-primary" aria-hidden="true" />
                            <p className="text-sm font-semibold text-foreground">
                                Ask about your HR data
                            </p>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                aria-label="Close"
                                className="ml-auto grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-secondary hover:text-foreground"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={submit} className="flex gap-2 p-4">
                            <input
                                ref={inputRef}
                                value={question}
                                onChange={(event) => setQuestion(event.target.value)}
                                placeholder="e.g. Ilang driver ang expired na ang lisensya?"
                                maxLength={500}
                                className="flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <Button type="submit" disabled={pending || !question.trim()}>
                                <Send className="h-4 w-4" />
                                {pending ? 'Asking…' : 'Ask'}
                            </Button>
                        </form>

                        <div className="max-h-80 overflow-y-auto px-4 pb-4">
                            {error && (
                                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                    {error}
                                </p>
                            )}

                            {result && (
                                <div>
                                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                                        {result.answer}
                                    </p>

                                    {/* Naming the lookups is the difference between an
                                        answer you can check and one you have to trust. */}
                                    {result.used?.length > 0 && (
                                        <p className="mt-3 border-t border-border pt-2 text-xs text-muted-foreground">
                                            Read from:{' '}
                                            {[...new Set(result.used)]
                                                .map((tool) => tool.replace(/_/g, ' '))
                                                .join(', ')}
                                        </p>
                                    )}
                                </div>
                            )}

                            {!result && !error && !pending && (
                                <p className="text-xs text-muted-foreground">
                                    Answers come from your own records, and only from what your
                                    role already lets you see.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
