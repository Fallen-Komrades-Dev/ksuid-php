<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests;

use FallenKomradesDev\KSUID\Ksuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of ksuid-go/ksuid_test.go, using the same `tst` fixture
 * values so the expected outputs are byte-for-byte identical to Go's.
 *
 * tst = KSUID{
 *     Timestamp: 584049083,   // 0x22CFE1BB
 *     Seq:       0xC0FFEE,
 *     Partition: 0xDEADBEEF,
 *     Hash:      0x355016B7,
 * }
 * tst.String() == "22cfe1bb00c0ffeedeadbeef355016b7"
 * tst.Time()   == 2018-07-04T19:51:23Z
 *
 * @covers \FallenKomradesDev\KSUID\Ksuid
 */
final class KsuidTest extends TestCase
{
    private const TST_TIMESTAMP = 584049083; // 0x22CFE1BB
    private const TST_SEQ       = 0xC0FFEE;
    private const TST_PARTITION = 0xDEADBEEF;
    private const TST_HASH      = 0x355016B7;
    private const TST_HEX       = '22cfe1bb00c0ffeedeadbeef355016b7';

    protected function tearDown(): void
    {
        // Ksuid::setEpoch() mutates static state; always restore the
        // default between tests so ordering never matters.
        Ksuid::resetEpoch();
    }

    private function tst(): Ksuid
    {
        return new Ksuid(self::TST_TIMESTAMP, self::TST_SEQ, self::TST_PARTITION, self::TST_HASH);
    }

    public function testToStringMatchesGoFixture(): void
    {
        $this->assertSame(self::TST_HEX, $this->tst()->toString());
    }

    public function testStringableMagicMethodMatchesToString(): void
    {
        $this->assertSame(self::TST_HEX, (string) $this->tst());
        $this->assertSame($this->tst()->toString(), (string) $this->tst());
    }

    public function testValidateReturnsTrueForCorrectHash(): void
    {
        $this->assertTrue($this->tst()->validate());
    }

    public function testValidateReturnsFalseForTamperedHash(): void
    {
        $tampered = new Ksuid(self::TST_TIMESTAMP, self::TST_SEQ, self::TST_PARTITION, self::TST_HASH ^ 1);

        $this->assertFalse($tampered->validate());
    }

    public function testComputeHashMatchesGoFixture(): void
    {
        $this->assertSame(self::TST_HASH, $this->tst()->computeHash());
    }

    public function testBinaryEncodingMatchesGoFixture(): void
    {
        $expected = hex2bin(self::TST_HEX);

        $this->assertSame($expected, $this->tst()->binary());
        $this->assertSame(16, strlen($this->tst()->binary()));
    }

    public function testTimeMatchesGoFixture(): void
    {
        $time = $this->tst()->time();

        $this->assertSame('2018-07-04 19:51:23', $time->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $time->getTimezone()->getName());
    }

    public function testTimeRespectsCustomEpoch(): void
    {
        // Setting Epoch to 0 (Unix epoch) means Timestamp is interpreted
        // as raw Unix seconds.
        Ksuid::setEpoch(0);

        $k = new Ksuid(timestamp: 1000);

        $this->assertSame(1000, $k->time()->getTimestamp());
    }

    public function testDefaultEpochIsYear2000(): void
    {
        $expected = (new \DateTimeImmutable('2000-01-01T00:00:00+00:00'))->getTimestamp();

        $this->assertSame($expected, Ksuid::getEpoch());
    }

    public function testIsZeroTrueForDefaultConstruction(): void
    {
        $this->assertTrue((new Ksuid())->isZero());
    }

    public function testIsZeroFalseForFixture(): void
    {
        $this->assertFalse($this->tst()->isZero());
    }

    public function testIsZeroIgnoresHashField(): void
    {
        // A KSUID with all-zero Timestamp/Seq/Partition but a non-zero Hash
        // (which would itself be an invalid/corrupt KSUID per validate())
        // is still considered "zero" by IsZero, matching Go's behaviour of
        // only inspecting the three data fields.
        $corruptButZeroData = new Ksuid(hash: 0xDEADBEEF);

        $this->assertTrue($corruptButZeroData->isZero());
    }

    public static function compareProvider(): array
    {
        return [
            'lower seq, same ts/partition' => [
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                new Ksuid(timestamp: 1000, seq: 2, partition: 0),
                -1,
            ],
            'higher seq, same ts/partition' => [
                new Ksuid(timestamp: 1000, seq: 2, partition: 0),
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                1,
            ],
            'identical' => [
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                0,
            ],
            'higher timestamp wins regardless of seq' => [
                new Ksuid(timestamp: 1001, seq: 0, partition: 0),
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                1,
            ],
            'lower partition, same ts/seq' => [
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                new Ksuid(timestamp: 1000, seq: 1, partition: 1),
                -1,
            ],
            'higher partition, same ts/seq' => [
                new Ksuid(timestamp: 1000, seq: 1, partition: 1),
                new Ksuid(timestamp: 1000, seq: 1, partition: 0),
                1,
            ],
        ];
    }

    #[DataProvider('compareProvider')]
    public function testCompare(Ksuid $a, Ksuid $b, int $expected): void
    {
        $this->assertSame($expected, $a->compare($b));
    }

    public function testCompareIsAntiSymmetric(): void
    {
        $a = new Ksuid(timestamp: 5000, seq: 10, partition: 2);
        $b = new Ksuid(timestamp: 5000, seq: 20, partition: 2);

        $this->assertSame(-1 * $a->compare($b), $b->compare($a));
    }

    public function testEqualsRequiresAllFieldsIncludingHash(): void
    {
        $a = $this->tst();
        $b = $this->tst();
        $c = new Ksuid(self::TST_TIMESTAMP, self::TST_SEQ, self::TST_PARTITION, self::TST_HASH ^ 1);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testConstructorMasksOversizedValuesTo32Bits(): void
    {
        // Passing a value with bits set above bit 31 must be masked down,
        // since every field is conceptually a uint32.
        $k = new Ksuid(timestamp: 0x1_0000_0001, seq: 0x1_FFFF_FFFF);

        $this->assertSame(1, $k->timestamp);
        $this->assertSame(Ksuid::MASK32, $k->seq);
    }

    public function testJsonSerializeProducesHexString(): void
    {
        $json = json_encode($this->tst());

        $this->assertSame('"' . self::TST_HEX . '"', $json);
    }

    public function testFromJsonRoundTrips(): void
    {
        $json = json_encode($this->tst());
        $decoded = Ksuid::fromJson($json);

        $this->assertTrue($decoded->equals($this->tst()));
    }

    public function testFromJsonRejectsNonStringValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Ksuid::fromJson('42');
    }

    public function testKsuidIsJsonSerializableInsideAnArray(): void
    {
        $payload = ['id' => $this->tst(), 'name' => 'example'];
        $json = json_encode($payload);
        $decoded = json_decode($json, true);

        $this->assertSame(self::TST_HEX, $decoded['id']);
    }
}
