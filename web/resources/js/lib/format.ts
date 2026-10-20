export function formatDate(
    value: string | null | undefined,
    withTime = false,
    timeZone?: string,
): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        ...(withTime ? { timeStyle: 'short' } : {}),
        ...(timeZone ? { timeZone } : {}),
    });
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Render a loosely typed stored value (string/number/boolean/list) as input text. */
export function asText(value: unknown, separator = ', '): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (Array.isArray(value)) {
        return value.map((v) => asText(v)).join(separator);
    }
    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
    ) {
        return String(value);
    }

    return JSON.stringify(value);
}
