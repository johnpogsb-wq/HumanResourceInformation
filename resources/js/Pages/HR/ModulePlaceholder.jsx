import { Check, Construction } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody } from '@/Components/ui';

/**
 * Modules 2–5: schema and routes exist, UI is next up.
 */
export default function ModulePlaceholder({ module }) {
    return (
        <AppLayout
            title={module.title}
            breadcrumbs={[{ label: 'Human Resource' }, { label: module.title }]}
        >
            <Card>
                <CardBody className="flex flex-col items-center gap-4 py-14 text-center">
                    <span className="grid h-14 w-14 place-items-center rounded-full bg-primary/10 text-primary">
                        <Construction className="h-6 w-6" aria-hidden="true" />
                    </span>

                    <div className="max-w-xl">
                        <h2 className="text-base font-semibold text-foreground">
                            {module.title}
                        </h2>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            {module.description}
                        </p>
                    </div>

                    <div className="mt-2 w-full max-w-md rounded-lg border border-border bg-secondary/40 p-4 text-left">
                        <p className="mb-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Planned capabilities
                        </p>
                        <ul className="space-y-1.5">
                            {module.features.map((feature) => (
                                <li
                                    key={feature}
                                    className="flex items-start gap-2 text-sm text-foreground"
                                >
                                    <Check
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    {feature}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Database tables for this module are already migrated.
                    </p>
                </CardBody>
            </Card>
        </AppLayout>
    );
}
