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

export type WebhookResponseStatus = 'idle' | 'sending' | 'success' | 'error';

export const IDLE_WEBHOOK_RESPONSE_BODY = '';
export const EMPTY_WEBHOOK_PAYLOAD_ERROR = 'EMPTY_WEBHOOK_PAYLOAD';

export const DEFAULT_WEBHOOK_PAYLOAD = JSON.stringify(
    { message: 'hello' },
    null,
    4,
);

export const normalizeWebhookPayload = (
    value: string,
): NormalizedWebhookPayload => {
    const trimmedPayload = value.trim();

    if (trimmedPayload === '') {
        throw new Error(EMPTY_WEBHOOK_PAYLOAD_ERROR);
    }

    const requestBody = trimmedPayload;

    return {
        formattedPayload: JSON.stringify(JSON.parse(requestBody), null, 4),
        requestBody,
    };
};

export const isWebhookPayloadEmpty = (value: string): boolean => {
    return value.trim() === '';
};

export const formatWebhookResponseBody = (
    status: number,
    statusText: string,
    rawBody: string,
): string => {
    const formattedBody = rawBody.trim() === '' ? 'null' : rawBody;

    return `${status} ${statusText}\n${formattedBody}`;
};

export const shouldRenderWebhookResponse = (
    status: WebhookResponseStatus,
): boolean => {
    return status === 'success';
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
