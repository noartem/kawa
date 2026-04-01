import {
    dispatchWebhookPayload,
    normalizeWebhookPayload,
} from '@/lib/webhookDispatch';
import { ref, toValue, type MaybeRefOrGetter, type Ref } from 'vue';

export interface WebhookResponseState {
    status: 'idle' | 'sending' | 'success' | 'error';
    label: string;
    body: string;
}

export interface WebhookDispatchMessages {
    genericError: string;
    invalidJson: string;
    response: string;
    responseError: string;
    responseIdle: string;
    responseNetworkError: string;
    responseSuccess: string;
    sending: string;
}

export interface UseWebhookDispatchResult {
    isSubmitting: Ref<boolean>;
    payload: Ref<string>;
    responseState: Ref<WebhookResponseState>;
    submitPayload: () => Promise<void>;
    validationError: Ref<string | null>;
}

export const useWebhookDispatch = (
    endpoint: MaybeRefOrGetter<string>,
    initialPayload: string,
    messages: MaybeRefOrGetter<WebhookDispatchMessages>,
): UseWebhookDispatchResult => {
    const payload = ref(initialPayload);
    const validationError = ref<string | null>(null);
    const isSubmitting = ref(false);
    const responseState = ref<WebhookResponseState>({
        status: 'idle',
        label: toValue(messages).response,
        body: toValue(messages).responseIdle,
    });

    const resolveMessages = (): WebhookDispatchMessages => {
        return toValue(messages);
    };

    const setResponseState = (
        status: WebhookResponseState['status'],
        label: string,
        body: string,
    ): void => {
        responseState.value = { status, label, body };
    };

    const validatePayload = (): string | null => {
        try {
            const normalizedPayload = normalizeWebhookPayload(payload.value);
            payload.value = normalizedPayload.formattedPayload;
            validationError.value = null;

            return normalizedPayload.requestBody;
        } catch (error) {
            const resolvedMessages = resolveMessages();
            validationError.value =
                error instanceof Error
                    ? error.message
                    : resolvedMessages.genericError;
            setResponseState(
                'error',
                resolvedMessages.invalidJson,
                validationError.value,
            );

            return null;
        }
    };

    const submitPayload = async (): Promise<void> => {
        const normalizedPayload = validatePayload();

        if (isSubmitting.value || normalizedPayload === null) {
            return;
        }

        const resolvedMessages = resolveMessages();
        isSubmitting.value = true;
        setResponseState(
            'sending',
            resolvedMessages.sending,
            resolvedMessages.sending,
        );

        try {
            const response = await dispatchWebhookPayload(
                toValue(endpoint),
                normalizedPayload,
            );

            setResponseState(
                response.ok ? 'success' : 'error',
                response.ok
                    ? resolvedMessages.responseSuccess
                    : resolvedMessages.responseError,
                response.body,
            );
        } catch (error) {
            setResponseState(
                'error',
                resolvedMessages.responseNetworkError,
                error instanceof Error
                    ? error.message
                    : resolvedMessages.genericError,
            );
        } finally {
            isSubmitting.value = false;
        }
    };

    return {
        isSubmitting,
        payload,
        responseState,
        submitPayload,
        validationError,
    };
};
