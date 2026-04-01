export interface NormalizedWebhookPayload {
    formattedPayload: string;
    requestBody: string;
}

export interface WebhookDispatchResult {
    body: string;
    ok: boolean;
    status: number;
    statusText: string;
}

export const DEFAULT_WEBHOOK_PAYLOAD = JSON.stringify(
    { message: 'hello' },
    null,
    4,
);

export const normalizeWebhookPayload = (
    value: string,
): NormalizedWebhookPayload => {
    const trimmedPayload = value.trim();
    const requestBody = trimmedPayload === '' ? 'null' : trimmedPayload;

    return {
        formattedPayload: JSON.stringify(JSON.parse(requestBody), null, 4),
        requestBody,
    };
};

export const formatWebhookResponseBody = (
    status: number,
    statusText: string,
    rawBody: string,
): string => {
    const formattedBody = rawBody.trim() === '' ? 'null' : rawBody;

    return `${status} ${statusText}\n${formattedBody}`;
};

export const dispatchWebhookPayload = async (
    endpoint: string,
    payload: string,
): Promise<WebhookDispatchResult> => {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: payload,
    });

    return {
        body: formatWebhookResponseBody(
            response.status,
            response.statusText,
            await response.text(),
        ),
        ok: response.ok,
        status: response.status,
        statusText: response.statusText,
    };
};
