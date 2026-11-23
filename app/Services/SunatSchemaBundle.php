<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/** Resolves an installed, local SUNAT XSD bundle without network access. */
class SunatSchemaBundle
{
    public function rootPath(): string
    {
        return $this->resolvePath((string) config('electronic_invoicing.sunat.invoice_xsd'));
    }

    /** @return array{available: bool, root: ?string, files: array<int, string>, reason: ?string} */
    public function inspect(?string $configuredPath = null): array
    {
        $root = $configuredPath === null ? $this->rootPath() : $this->resolvePath($configuredPath);
        $realRoot = realpath($root);
        if ($realRoot === false || ! is_file($realRoot)) {
            return ['available' => false, 'root' => null, 'files' => [], 'reason' => 'official_ubl_schema_missing'];
        }

        $baseDirectory = realpath($this->resolvePath((string) config('electronic_invoicing.sunat.xsd_directory')));
        if ($baseDirectory === false) {
            return ['available' => false, 'root' => null, 'files' => [], 'reason' => 'official_ubl_schema_unreadable'];
        }
        if (! $this->isWithin($realRoot, $baseDirectory)) {
            return ['available' => false, 'root' => null, 'files' => [], 'reason' => 'official_ubl_schema_outside_bundle'];
        }

        $visited = [];
        $pending = [$realRoot];
        while ($pending !== []) {
            $file = array_pop($pending);
            $realFile = realpath($file);
            if ($realFile === false || ! is_file($realFile) || ! $this->isWithin($realFile, $baseDirectory)) {
                return ['available' => false, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => 'official_ubl_schema_dependency_missing_or_external'];
            }
            if (isset($visited[$realFile])) {
                continue;
            }
            $visited[$realFile] = true;

            $schema = new DOMDocument;
            if (! $schema->load($realFile, LIBXML_NONET | LIBXML_NOBLANKS)) {
                return ['available' => false, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => 'official_ubl_schema_invalid'];
            }
            if ($schema->documentElement?->namespaceURI !== 'http://www.w3.org/2001/XMLSchema'
                || $schema->documentElement?->localName !== 'schema') {
                return ['available' => false, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => 'official_ubl_schema_invalid'];
            }

            foreach ($schema->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', '*') as $node) {
                if (! $node instanceof DOMElement || ! in_array($node->localName, ['include', 'import', 'redefine', 'override'], true)) {
                    continue;
                }
                $location = trim($node->getAttribute('schemaLocation'));
                if ($location === '') {
                    if ($node->localName === 'import') {
                        continue;
                    }

                    return ['available' => false, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => 'official_ubl_schema_dependency_missing_or_external'];
                }
                if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $location) === 1 || str_starts_with($location, '/') || str_contains($location, '\\')) {
                    return ['available' => false, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => 'official_ubl_schema_dependency_missing_or_external'];
                }
                $pending[] = dirname($realFile).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $location);
            }
        }

        return ['available' => true, 'root' => $realRoot, 'files' => array_keys($visited), 'reason' => null];
    }

    public function isAvailable(): bool
    {
        return $this->inspect()['available'];
    }

    public function validate(DOMDocument $xml): bool
    {
        return $this->validateWith($xml, null);
    }

    public function validateWith(DOMDocument $xml, ?string $configuredPath): bool
    {
        $bundle = $this->inspect($configuredPath);
        if (! $bundle['available'] || ! is_string($bundle['root'])) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            return $xml->schemaValidate($bundle['root'], LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function isWithin(string $path, string $directory): bool
    {
        $prefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with(strtolower($path), strtolower($prefix));
    }

    private function resolvePath(string $path): string
    {
        $absolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (strlen($path) >= 3
                && ctype_alpha($path[0])
                && $path[1] === ':'
                && ($path[2] === '\\' || $path[2] === '/'));

        if ($path === '' || $absolute) {
            return $path;
        }

        return base_path($path);
    }
}
