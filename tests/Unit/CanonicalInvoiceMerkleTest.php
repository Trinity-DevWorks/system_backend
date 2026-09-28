<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\InvoiceProof\Support\CanonicalInvoiceMerkle;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Salted Merkle tree: leaf order, proofs, tamper and salt checks, odd promotion.
 */
class CanonicalInvoiceMerkleTest extends TestCase
{
    private const SECRET = 'abababababababababababababababababababababababababababababababab';

    private const JSON = '{"schema_version":2,"invoice_number":"INV-0001","buyer":{"name":"مشتري","tax_number":null},"salesman":null,"lines":[{"sort_order":0,"unit_price":"10.0000"},{"sort_order":1,"unit_price":"5.5000"}],"grand_total":"22.0000"}';

    public function test_leaves_are_dot_paths_in_document_order(): void
    {
        $tree = CanonicalInvoiceMerkle::build(self::JSON, self::SECRET);

        $this->assertSame(
            [
                'schema_version',
                'invoice_number',
                'buyer.name',
                'buyer.tax_number',
                'salesman',
                'lines.0.sort_order',
                'lines.0.unit_price',
                'lines.1.sort_order',
                'lines.1.unit_price',
                'grand_total',
            ],
            array_column($tree->leaves(), 'path'),
        );
        $this->assertSame(10, $tree->leafCount());
        $this->assertNull($tree->leaves()[4]['value']);
        $this->assertSame(0, $tree->leaves()[5]['value']);
    }

    public function test_every_leaf_proof_verifies_against_the_root(): void
    {
        $tree = CanonicalInvoiceMerkle::build(self::JSON, self::SECRET);

        foreach ($tree->leaves() as $index => $leaf) {
            $this->assertTrue(CanonicalInvoiceMerkle::verify(
                $leaf['path'],
                $leaf['value'],
                $tree->saltAt($index),
                $tree->proof($index),
                $tree->root(),
            ), $leaf['path']);
        }
    }

    public function test_changed_value_path_or_salt_fails_verification(): void
    {
        $tree = CanonicalInvoiceMerkle::build(self::JSON, self::SECRET);
        $index = (int) $tree->indexOf('grand_total');
        $salt = $tree->saltAt($index);
        $proof = $tree->proof($index);

        $this->assertFalse(CanonicalInvoiceMerkle::verify('grand_total', '23.0000', $salt, $proof, $tree->root()));
        $this->assertFalse(CanonicalInvoiceMerkle::verify('tax_total', '22.0000', $salt, $proof, $tree->root()));
        $this->assertFalse(CanonicalInvoiceMerkle::verify('grand_total', '22.0000', str_repeat('00', 32), $proof, $tree->root()));
        $this->assertFalse(CanonicalInvoiceMerkle::verify('lines.0.sort_order', '0', $tree->saltAt(5), $tree->proof(5), $tree->root()));
    }

    public function test_odd_last_node_is_promoted_not_duplicated(): void
    {
        $tree = CanonicalInvoiceMerkle::build('{"a":"1","b":"2","c":"3"}', self::SECRET);

        $this->assertSame([['position' => 'left', 'hash' => $this->nodeHex($tree, 0, 1)]], $tree->proof(2));
        $this->assertCount(2, $tree->proof(0));
    }

    public function test_secret_changes_root_and_salts(): void
    {
        $first = CanonicalInvoiceMerkle::build(self::JSON, self::SECRET);
        $second = CanonicalInvoiceMerkle::build(self::JSON, str_repeat('cd', 32));

        $this->assertNotSame($first->root(), $second->root());
        $this->assertNotSame($first->saltAt(0), $second->saltAt(0));
    }

    public function test_rejects_invalid_documents_and_secrets(): void
    {
        foreach (['not json', '[1,2]', '{"amount":1.5}'] as $json) {
            try {
                CanonicalInvoiceMerkle::build($json, self::SECRET);
                $this->fail('Expected rejection for '.$json);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        CanonicalInvoiceMerkle::build(self::JSON, 'short');
    }

    private function nodeHex(CanonicalInvoiceMerkle $tree, int $left, int $right): string
    {
        $leftHash = $this->leafHex($tree, $left);
        $rightHash = $this->leafHex($tree, $right);

        return hash('sha256', "\x01".hex2bin($leftHash).hex2bin($rightHash));
    }

    private function leafHex(CanonicalInvoiceMerkle $tree, int $index): string
    {
        $leaf = $tree->leaves()[$index];

        return hash('sha256', "\x00".hex2bin($tree->saltAt($index)).$leaf['path']."\x00".'s'.$leaf['value']);
    }
}
