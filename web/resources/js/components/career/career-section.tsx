import { Form, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import CareerItemController from '@/actions/App/Http/Controllers/CareerItemController';
import Field from '@/components/field';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { asText } from '@/lib/format';

export type Section = 'experiences' | 'skills' | 'projects' | 'education';

export type FieldDef = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'date' | 'number' | 'url' | 'select';
    required?: boolean;
    hint?: string;
    options?: string[];
    /** How a stored value becomes the input's default (e.g. arrays -> one per line). */
    toInput?: (value: unknown) => string;
    wide?: boolean;
};

type Item = { id: number } & Record<string, unknown>;

export const lines = (v: unknown) => (Array.isArray(v) ? v.join('\n') : '');
export const commas = (v: unknown) => (Array.isArray(v) ? v.join(', ') : '');

function inputValue(field: FieldDef, item?: Item): string {
    if (!item) {
        return '';
    }
    const value = item[field.name];

    if (field.toInput) {
        return field.toInput(value);
    }

    return asText(value);
}

function ItemDialog({
    section,
    title,
    fields,
    item,
    open,
    onOpenChange,
}: {
    section: Section;
    title: string;
    fields: FieldDef[];
    item?: Item;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const action = item
        ? CareerItemController.update.form({ section, item: item.id })
        : CareerItemController.store.form({ section });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {item ? `Edit ${title}` : `Add ${title}`}
                    </DialogTitle>
                    <DialogDescription>
                        Only add information you can back up. It is used as
                        evidence.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="grid gap-4 sm:grid-cols-2"
                >
                    {({ processing, errors }) => (
                        <>
                            {fields.map((field) => {
                                const id = `${section}-${field.name}`;
                                const common = {
                                    id,
                                    name: field.name,
                                    required: field.required,
                                    defaultValue: inputValue(field, item),
                                };

                                return (
                                    <Field
                                        key={field.name}
                                        label={field.label}
                                        htmlFor={id}
                                        hint={field.hint}
                                        error={
                                            (
                                                errors as Record<
                                                    string,
                                                    string | undefined
                                                >
                                            )[field.name]
                                        }
                                        className={
                                            field.wide ||
                                            field.type === 'textarea'
                                                ? 'sm:col-span-2'
                                                : ''
                                        }
                                    >
                                        {field.type === 'textarea' ? (
                                            <Textarea rows={4} {...common} />
                                        ) : field.type === 'select' ? (
                                            <NativeSelect {...common}>
                                                <option value="">—</option>
                                                {field.options?.map((o) => (
                                                    <option key={o} value={o}>
                                                        {o}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        ) : (
                                            <Input
                                                type={field.type ?? 'text'}
                                                step={
                                                    field.type === 'number'
                                                        ? '0.5'
                                                        : undefined
                                                }
                                                {...common}
                                            />
                                        )}
                                    </Field>
                                );
                            })}
                            <DialogFooter className="sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button disabled={processing}>Save</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function CareerSection({
    section,
    title,
    itemLabel,
    fields,
    items,
    render,
    disabled,
}: {
    section: Section;
    title: string;
    itemLabel: string;
    fields: FieldDef[];
    items: Item[];
    render: (item: Item) => ReactNode;
    disabled?: boolean;
}) {
    const [editing, setEditing] = useState<Item | undefined>();
    const [open, setOpen] = useState(false);

    const openFor = (item?: Item) => {
        setEditing(item);
        setOpen(true);
    };

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>{title}</CardTitle>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => openFor()}
                    disabled={disabled}
                >
                    <Plus /> Add
                </Button>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {disabled
                            ? 'Save your basic profile first.'
                            : `No ${title.toLowerCase()} yet.`}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {items.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-start justify-between gap-4 py-3"
                            >
                                <div className="min-w-0 text-sm">
                                    {render(item)}
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        onClick={() => openFor(item)}
                                        aria-label="Edit"
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        asChild
                                        aria-label="Delete"
                                    >
                                        <Link
                                            href={CareerItemController.destroy({
                                                section,
                                                item: item.id,
                                            })}
                                            as="button"
                                            preserveScroll
                                            onBefore={() =>
                                                confirm(
                                                    `Delete this ${itemLabel}?`,
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
            <ItemDialog
                key={editing?.id ?? 'new'}
                section={section}
                title={itemLabel}
                fields={fields}
                item={editing}
                open={open}
                onOpenChange={setOpen}
            />
        </Card>
    );
}
