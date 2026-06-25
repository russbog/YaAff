<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../admin/entityschemas.php';

/**
 * Guards the declarative entity-form metadata (Phase 2 form depth): every
 * schema must be JSON-serialisable, selects must carry options, conditional
 * fields must point at sibling fields, and the offer/network/source forms must
 * expose the enriched currency catalog + token reference.
 */
class EntitySchemaTest extends TestCase
{
    public function testAllSchemasAreJsonSerialisable(): void
    {
        $json = json_encode(entity_schemas());
        $this->assertIsString($json);
        $this->assertNotFalse($json);
    }

    public function testSelectFieldsAlwaysCarryOptions(): void
    {
        foreach (entity_schemas() as $type => $schema) {
            foreach ($schema['fields'] as $f) {
                if (($f['type'] ?? '') === 'select') {
                    $this->assertNotEmpty(
                        $f['options'] ?? [],
                        "select $type.{$f['key']} must have options"
                    );
                }
            }
        }
    }

    public function testShowIfReferencesAnExistingSiblingField(): void
    {
        foreach (entity_schemas() as $type => $schema) {
            $keys = array_column($schema['fields'], 'key');
            foreach ($schema['fields'] as $f) {
                if (isset($f['showIf'])) {
                    $this->assertArrayHasKey('field', $f['showIf']);
                    $this->assertIsArray($f['showIf']['in']);
                    $this->assertContains(
                        $f['showIf']['field'],
                        $keys,
                        "showIf on $type.{$f['key']} points at unknown field {$f['showIf']['field']}"
                    );
                }
            }
        }
    }

    public function testOfferCurrencyIsACatalogSelect(): void
    {
        $fields = entity_schema('offers')['fields'];
        $currency = $this->field($fields, 'currency');
        $this->assertSame('select', $currency['type']);
        $this->assertArrayHasKey('USD', $currency['options']);
        $this->assertArrayHasKey('EUR', $currency['options']);
        $this->assertGreaterThanOrEqual(20, count($currency['options']));
    }

    public function testOfferIsAlwaysARedirectLink(): void
    {
        $fields = entity_schema('offers')['fields'];
        $keys = array_column($fields, 'key');
        // An offer is a redirect link only; the legacy local-landing type was removed.
        $this->assertNotContains('type', $keys, 'offers must not expose a type field');

        $url = $this->field($fields, 'url');
        $this->assertArrayNotHasKey('showIf', $url, 'offer URL is always shown (no type gate)');
        $this->assertNotEmpty($url['tokens']);

        $redirectType = $this->field($fields, 'redirect_type');
        $this->assertArrayNotHasKey('showIf', $redirectType, 'offer redirect type is always shown');
    }

    public function testSourcePostbackExposesLiveTokens(): void
    {
        $fields = entity_schema('sources')['fields'];
        $pb = $this->field($fields, 'postback_url');
        $this->assertContains('{clickid}', $pb['tokens']);
        $this->assertContains('{status}', $pb['tokens']);
        $this->assertContains('{payout}', $pb['tokens']);
    }

    public function testCurrencyCatalogIsLabelled(): void
    {
        $opts = currency_options();
        $this->assertStringContainsString('US Dollar', $opts['USD']);
        $this->assertStringStartsWith('USD', $opts['USD']);
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function field(array $fields, string $key): array
    {
        foreach ($fields as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }
        $this->fail("field $key not found");
    }
}
