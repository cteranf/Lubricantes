<?php

namespace App\Services;

use Illuminate\Http\Request;

class PaymentWebhookNormalizer
{
    public function normalize(Request $request): array
    {
        // Never read the parsed query bag: PHP aliases dots, spaces and arrays.
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        $query = [];
        if ($rawQuery !== '') {
            foreach (explode('&', $rawQuery) as $segment) {
                if ($segment === '' || ! str_contains($segment, '=')) {
                    $this->invalid();
                }
                [$name, $value] = explode('=', $segment, 2);
                foreach ([$name, $value] as $encoded) {
                    if (preg_match('/%(?![0-9a-fA-F]{2})/', $encoded)) {
                        $this->invalid();
                    }
                }
                $name = urldecode($name);
                $value = urldecode($value);
                if (! in_array($name, ['data.id', 'type'], true) || array_key_exists($name, $query)) {
                    $this->invalid();
                }
                $query[$name] = $value;
            }
        }
        $body = new \stdClass;
        if ($request->getContent() !== '') {
            if (! $request->isJson()) {
                $this->invalid();
            }
            try {
                $body = json_decode($request->getContent(), false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->invalid();
            }
            if (! $body instanceof \stdClass) {
                $this->invalid();
            }
            $this->rejectDuplicateJsonKeys($request->getContent());
        }
        if (property_exists($body, 'topic') || property_exists($body, 'data.id') || property_exists($body, 'data_id')) {
            $this->invalid();
        }
        $bodyId = null;
        if (property_exists($body, 'data')) {
            if (! $body->data instanceof \stdClass || ! property_exists($body->data, 'id')) {
                $this->invalid();
            }
            $bodyId = $this->id($body->data->id);
        }
        $queryId = array_key_exists('data.id', $query) ? $this->id($query['data.id']) : null;
        if (($bodyId === null && $queryId === null) || ($bodyId !== null && $queryId !== null && $bodyId !== $queryId)) {
            $this->invalid();
        }
        $topics = [];
        if (property_exists($body, 'type')) {
            $topics[] = $body->type;
        }
        if (array_key_exists('type', $query)) {
            $topics[] = $query['type'];
        }
        if ($topics === [] || count(array_filter($topics, static fn ($topic) => $topic !== 'payment')) !== 0) {
            $this->invalid();
        }
        $paymentId = $bodyId ?? $queryId;
        $payload = ['type' => 'payment', 'data' => ['id' => $paymentId]];
        foreach (['id', 'action'] as $field) {
            if (property_exists($body, $field)) {
                $payload[$field] = $this->id($body->$field);
            }
        }

        return ['payment_id' => $paymentId, 'payload' => $payload];
    }

    private function id(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', (string) $value) !== 1) {
            $this->invalid();
        }

        return (string) $value;
    }

    private function rejectDuplicateJsonKeys(string $json): void
    {
        // JSON has already passed the grammar/depth check. Track object keys
        // before json_decode's last-key-wins semantics can authorize a request.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => true, 'seen' => []];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack !== []) {
                $index = count($stack) - 1;
                if ($token === ':') {
                    $stack[$index]['key'] = false;
                } elseif ($token === ',') {
                    $stack[$index]['key'] = true;
                } elseif ($token[0] === '"' && $stack[$index]['object'] && $stack[$index]['key']) {
                    $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                    if (array_key_exists($key, $stack[$index]['seen'])) {
                        $this->invalid();
                    }
                    $stack[$index]['seen'][$key] = true;
                }
            }
        }
    }

    private function invalid(): never
    {
        throw new \InvalidArgumentException('Invalid payment webhook envelope.');
    }
}
