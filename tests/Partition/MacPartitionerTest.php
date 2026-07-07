<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\Partition;

use FallenKomradesDev\KSUID\Exception\NoNetworkInterfaceException;
use FallenKomradesDev\KSUID\Ksuid;
use FallenKomradesDev\KSUID\Partition\MacPartitioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MacPartitioner, focused on the three pure parsing functions
 * (parseLinuxAddresses, parseWindowsAddresses, parseMacOsAddresses) and the
 * normalisation helpers (normaliseMac, isUsableMac).
 *
 * These cover the logic that was previously untestable because it was tangled
 * with OS I/O. The only parts that still can't be meaningfully unit-tested are
 * the thin Layer 1 acquisition functions (discoverLinux/discoverWindows/
 * discoverMacOs), which contain no logic beyond an I/O call and a null check.
 *
 * @covers \FallenKomradesDev\KSUID\Partition\MacPartitioner
 */
final class MacPartitionerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // normaliseMac
    // -------------------------------------------------------------------------

    public static function normaliseMacValidProvider(): array
    {
        return [
            'colon-separated lowercase' => ['02:fc:00:00:00:01', "\x02\xfc\x00\x00\x00\x01"],
            'colon-separated uppercase' => ['02:FC:00:00:00:01', "\x02\xfc\x00\x00\x00\x01"],
            'colon-separated mixed case' => ['De:Ad:Be:Ef:00:01', "\xde\xad\xbe\xef\x00\x01"],
            'hyphen-separated (Windows style)' => ['02-FC-00-00-00-01', "\x02\xfc\x00\x00\x00\x01"],
            'all zeros' => ['00:00:00:00:00:00', "\x00\x00\x00\x00\x00\x00"],
            'broadcast' => ['ff:ff:ff:ff:ff:ff', "\xff\xff\xff\xff\xff\xff"],
        ];
    }

    #[DataProvider('normaliseMacValidProvider')]
    public function testNormaliseMacValidInput(string $input, string $expected): void
    {
        $this->assertSame($expected, MacPartitioner::normaliseMac($input));
    }

    public static function normaliseMacInvalidProvider(): array
    {
        return [
            'too short' => ['02:fc:00:00:00'],
            'too long' => ['02:fc:00:00:00:01:ff'],
            'invalid hex chars' => ['gg:fc:00:00:00:01'],
            'wrong separator' => ['02.fc.00.00.00.01'],
            'empty string' => [''],
            'random text' => ['not-a-mac'],
        ];
    }

    #[DataProvider('normaliseMacInvalidProvider')]
    public function testNormaliseMacInvalidInputReturnsNull(string $input): void
    {
        $this->assertNull(MacPartitioner::normaliseMac($input));
    }

    // -------------------------------------------------------------------------
    // isUsableMac
    // -------------------------------------------------------------------------

    public function testIsUsableMacReturnsTrueForRealMac(): void
    {
        $mac = MacPartitioner::normaliseMac('02:fc:00:00:00:01');
        $this->assertTrue(MacPartitioner::isUsableMac($mac));
    }

    public function testIsUsableMacReturnsFalseForNull(): void
    {
        $this->assertFalse(MacPartitioner::isUsableMac(null));
    }

    public function testIsUsableMacReturnsFalseForAllZeroMac(): void
    {
        $mac = MacPartitioner::normaliseMac('00:00:00:00:00:00');
        $this->assertFalse(MacPartitioner::isUsableMac($mac));
    }

    // -------------------------------------------------------------------------
    // parseLinuxAddresses
    // -------------------------------------------------------------------------

    public function testParseLinuxAddressesReturnsFirstUsableAddress(): void
    {
        $raw = "eth0:02:fc:00:00:00:01\nwlan0:aa:bb:cc:dd:ee:ff";

        $this->assertSame('02:fc:00:00:00:01', MacPartitioner::parseLinuxAddresses($raw));
    }

    public function testParseLinuxAddressesSkipsLoopback(): void
    {
        $raw = "lo:00:00:00:00:00:00\neth0:02:fc:00:00:00:01";

        $this->assertSame('02:fc:00:00:00:01', MacPartitioner::parseLinuxAddresses($raw));
    }

    public function testParseLinuxAddressesSkipsAllZeroAddress(): void
    {
        $raw = "eth0:00:00:00:00:00:00\neth1:de:ad:be:ef:00:01";

        $this->assertSame('de:ad:be:ef:00:01', MacPartitioner::parseLinuxAddresses($raw));
    }

    public function testParseLinuxAddressesReturnsNullWhenNoUsableInterface(): void
    {
        $raw = "lo:00:00:00:00:00:00\neth0:00:00:00:00:00:00";

        $this->assertNull(MacPartitioner::parseLinuxAddresses($raw));
    }

    public function testParseLinuxAddressesHandlesEmptyInput(): void
    {
        $this->assertNull(MacPartitioner::parseLinuxAddresses(''));
    }

    public function testParseLinuxAddressesHandlesCrlfLineEndings(): void
    {
        $raw = "eth0:02:fc:00:00:00:01\r\nwlan0:aa:bb:cc:dd:ee:ff";

        $this->assertSame('02:fc:00:00:00:01', MacPartitioner::parseLinuxAddresses($raw));
    }

    // -------------------------------------------------------------------------
    // parseWindowsAddresses
    // -------------------------------------------------------------------------

    public function testParseWindowsAddressesReturnsFirstUsableAddress(): void
    {
        // Matches real `getmac /fo csv /nh` output format
        $raw = '"02-FC-00-00-00-01","\\Device\\Tcpip_{ABC}"';

        $this->assertSame('02:FC:00:00:00:01', MacPartitioner::parseWindowsAddresses($raw));
    }

    public function testParseWindowsAddressesHandlesMultipleAdapters(): void
    {
        $raw = '"02-FC-00-00-00-01","\\Device\\Tcpip_{ABC}"' . "\r\n"
             . '"DE-AD-BE-EF-00-01","\\Device\\Tcpip_{DEF}"';

        $this->assertSame('02:FC:00:00:00:01', MacPartitioner::parseWindowsAddresses($raw));
    }

    public function testParseWindowsAddressesSkipsEmptyFirstColumn(): void
    {
        $raw = '"","\\Device\\Tcpip_{ABC}"' . "\r\n"
             . '"DE-AD-BE-EF-00-01","\\Device\\Tcpip_{DEF}"';

        $this->assertSame('DE:AD:BE:EF:00:01', MacPartitioner::parseWindowsAddresses($raw));
    }

    public function testParseWindowsAddressesSkipsAllZeroAddress(): void
    {
        $raw = '"00-00-00-00-00-00","\\Device\\Tcpip_{ABC}"' . "\r\n"
             . '"DE-AD-BE-EF-00-01","\\Device\\Tcpip_{DEF}"';

        $this->assertSame('DE:AD:BE:EF:00:01', MacPartitioner::parseWindowsAddresses($raw));
    }

    public function testParseWindowsAddressesReturnsNullWhenNoUsableAdapter(): void
    {
        $raw = '"00-00-00-00-00-00","\\Device\\Tcpip_{ABC}"';

        $this->assertNull(MacPartitioner::parseWindowsAddresses($raw));
    }

    public function testParseWindowsAddressesHandlesEmptyInput(): void
    {
        $this->assertNull(MacPartitioner::parseWindowsAddresses(''));
    }

    // -------------------------------------------------------------------------
    // parseMacOsAddresses
    // -------------------------------------------------------------------------

    public function testParseMacOsAddressesReturnsFirstNonLoopbackEtherAddress(): void
    {
        // Trimmed-down fixture matching real `ifconfig` output structure
        $raw = <<<'IFCONFIG'
lo0: flags=8049<UP,LOOPBACK,RUNNING,MULTICAST> mtu 16384
    options=1203<RXCSUM,TXCSUM,TXSTATUS,SW_TIMESTAMP>
    inet 127.0.0.1 netmask 0xff000000
en0: flags=8863<UP,BROADCAST,SMART,RUNNING,SIMPLEX,MULTICAST> mtu 1500
    options=6463<RXCSUM,TXCSUM,TSO4,TSO6,CHANNEL_IO,PARTIAL_CSUM,ZEROINVERT>
    ether a4:cf:99:01:02:03
    inet 192.168.1.10 netmask 0xffffff00 broadcast 192.168.1.255
IFCONFIG;

        $this->assertSame('a4:cf:99:01:02:03', MacPartitioner::parseMacOsAddresses($raw));
    }

    public function testParseMacOsAddressesSkipsLoopbackBlock(): void
    {
        $raw = <<<'IFCONFIG'
lo0: flags=8049<UP,LOOPBACK>
    ether 00:00:00:00:00:00
en0: flags=8863<UP,BROADCAST>
    ether de:ad:be:ef:00:01
IFCONFIG;

        $this->assertSame('de:ad:be:ef:00:01', MacPartitioner::parseMacOsAddresses($raw));
    }

    public function testParseMacOsAddressesReturnsNullWhenNoEtherFound(): void
    {
        $raw = <<<'IFCONFIG'
lo0: flags=8049<UP,LOOPBACK>
    inet 127.0.0.1
utun0: flags=8051<UP,POINTOPOINT>
    inet 10.0.0.1
IFCONFIG;

        $this->assertNull(MacPartitioner::parseMacOsAddresses($raw));
    }

    public function testParseMacOsAddressesSkipsAllZeroAddress(): void
    {
        $raw = <<<'IFCONFIG'
en0: flags=8863<UP,BROADCAST>
    ether 00:00:00:00:00:00
en1: flags=8863<UP,BROADCAST>
    ether aa:bb:cc:dd:ee:ff
IFCONFIG;

        $this->assertSame('aa:bb:cc:dd:ee:ff', MacPartitioner::parseMacOsAddresses($raw));
    }

    public function testParseMacOsAddressesHandlesEmptyInput(): void
    {
        $this->assertNull(MacPartitioner::parseMacOsAddresses(''));
    }

    // -------------------------------------------------------------------------
    // Integration: the full partitioner (environment-dependent)
    // -------------------------------------------------------------------------

    public function testEitherProducesAValidPartitionOrThrowsDocumentedException(): void
    {
        try {
            $partitioner = new MacPartitioner();
            $value = $partitioner();

            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(Ksuid::MASK32, $value);
        } catch (NoNetworkInterfaceException $e) {
            $this->assertSame(
                'No network interface with hardware address found',
                $e->getMessage()
            );
        }
    }

    public function testValueIsStableAcrossMultipleCallsOnSameInstance(): void
    {
        try {
            $partitioner = new MacPartitioner();
        } catch (NoNetworkInterfaceException) {
            $this->markTestSkipped('No suitable network interface available in this environment.');
        }

        $this->assertSame($partitioner(), $partitioner());
    }

    public function testTwoInstancesOnSameMachineProduceSameValue(): void
    {
        try {
            $a = new MacPartitioner();
            $b = new MacPartitioner();
        } catch (NoNetworkInterfaceException) {
            $this->markTestSkipped('No suitable network interface available in this environment.');
        }

        $this->assertSame($a(), $b());
    }
}
