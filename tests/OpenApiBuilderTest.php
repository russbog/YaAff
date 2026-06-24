<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/OpenApiBuilder.php';

class OpenApiBuilderTest extends TestCase
{
    public function testTopLevelStructure(): void
    {
        $spec = OpenApiBuilder::build();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertSame('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function testEveryEntityTypeHasCrudPaths(): void
    {
        $spec = OpenApiBuilder::build('/api/rest.php');
        foreach (array_keys(Repositories::TYPES) as $type) {
            if (entity_schema($type) === null) {
                continue;
            }
            $this->assertArrayHasKey("/api/rest.php/$type", $spec['paths'], "missing collection path for $type");
            $this->assertArrayHasKey("/api/rest.php/$type/{id}", $spec['paths'], "missing item path for $type");
            $coll = $spec['paths']["/api/rest.php/$type"];
            $this->assertArrayHasKey('get', $coll);
            $this->assertArrayHasKey('post', $coll);
            $item = $spec['paths']["/api/rest.php/$type/{id}"];
            foreach (['get', 'put', 'patch', 'delete'] as $m) {
                $this->assertArrayHasKey($m, $item, "missing $m for $type/{id}");
            }
        }
    }

    public function testGroupsHasNoSchemaSoExcluded(): void
    {
        $spec = OpenApiBuilder::build();
        $this->assertArrayNotHasKey('/api/rest.php/groups', $spec['paths']);
    }

    public function testPasswordFieldIsWriteOnly(): void
    {
        $spec = OpenApiBuilder::build();
        $userProps = $spec['components']['schemas']['Users']['properties'];
        $this->assertTrue($userProps['password']['writeOnly']);
        $this->assertSame('password', $userProps['password']['format']);
    }
}
