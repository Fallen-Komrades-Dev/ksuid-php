<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Partition;

use FallenKomradesDev\KSUID\Exception\NoNetworkInterfaceException;

/**
 * MacPartitioner creates a partitioner based on the last 4 bytes of the
 * primary network interface's MAC address, matching Go's MacPartitioner().
 *
 * Go has direct OS support for this via net.Interfaces(). PHP has no
 * standard-library equivalent, so this class discovers the MAC address by
 * platform-appropriate means:
 *
 *   - Linux: reads /sys/class/net/<iface>/address, skipping loopback and
 *     any interface whose address is all-zero.
 *   - Windows: shells out to `getmac /fo csv /nh` and parses the first
 *     physical adapter's MAC from the CSV output.
 *   - macOS: shells out to `ifconfig` and extracts the first non-loopback
 *     adapter's "ether" address.
 *   - Any other platform, or if discovery fails: throws
 *     NoNetworkInterfaceException, mirroring Go's ErrNoNetworkInterface.
 *
 * Architecture — three layers:
 *
 *   1. OS-acquisition  (discoverLinux / discoverWindows / discoverMacOs)
 *      Performs the OS-specific I/O (file reads, shell commands) and returns
 *      the raw output as a plain string, or null on failure. No parsing logic.
 *      These are the only parts that cannot be meaningfully unit-tested.
 *
 *   2. Parsing  (parseLinuxAddresses / parseWindowsAddresses / parseMacOsAddresses)
 *      Public pure functions — fully testable with fixture strings, no OS calls.
 *      Each delegates to the shared parseAddresses() template, supplying only
 *      the platform-specific extraction logic as a callable.
 *
 *   3. Normalisation  (normaliseMac / isUsableMac)
 *      Validates and converts MAC candidates to raw binary. Pure and fully
 *      testable independently.
 *
 * @throws NoNetworkInterfaceException if no suitable interface is found
 */
final class MacPartitioner implements PartitionerInterface
{
    private readonly int $value;

    public function __construct()
    {
        $mac = self::discoverMacAddress();

        if ($mac === null) {
            throw new NoNetworkInterfaceException();
        }

        $this->value = self::lastFourBytesAsUint32($mac);
    }

    public function __invoke(): int
    {
        return $this->value;
    }

    // -------------------------------------------------------------------------
    // Layer 0 — orchestration
    // -------------------------------------------------------------------------

    /**
     * discoverMacAddress returns the raw 6-byte binary MAC of the first
     * suitable interface, or null if none could be found on this platform.
     */
    private static function discoverMacAddress(): ?string
    {
        $os = PHP_OS_FAMILY;

        if ($os === 'Linux') {
            $raw = self::discoverLinux();
            $candidate = $raw !== null ? self::parseLinuxAddresses($raw) : null;
        } elseif ($os === 'Windows') {
            $raw = self::discoverWindows();
            $candidate = $raw !== null ? self::parseWindowsAddresses($raw) : null;
        } elseif ($os === 'Darwin') {
            $raw = self::discoverMacOs();
            $candidate = $raw !== null ? self::parseMacOsAddresses($raw) : null;
        } else {
            return null;
        }

        if ($candidate === null) {
            return null;
        }

        $mac = self::normaliseMac($candidate);

        return self::isUsableMac($mac) ? $mac : null;
    }

    // -------------------------------------------------------------------------
    // Layer 1 — OS acquisition (I/O only, no parsing)
    // -------------------------------------------------------------------------

    /**
     * discoverLinux reads every /sys/class/net/<iface>/address file and
     * returns all non-loopback entries as "iface:raw_address_content\n"
     * lines, or null if the sysfs path is unavailable.
     */
    private static function discoverLinux(): ?string
    {
        $base = '/sys/class/net';

        if (!is_dir($base)) {
            return null;
        }

        $interfaces = @scandir($base);
        if ($interfaces === false) {
            return null;
        }

        $lines = [];
        foreach ($interfaces as $iface) {
            if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                continue;
            }

            $addrPath = $base . '/' . $iface . '/address';
            if (!is_readable($addrPath)) {
                continue;
            }

            $content = @file_get_contents($addrPath);
            if ($content === false) {
                continue;
            }

            $lines[] = $iface . ':' . trim($content);
        }

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    /**
     * discoverWindows shells out to `getmac /fo csv /nh` and returns its
     * raw stdout output, or null if the command fails or produces no output.
     */
    private static function discoverWindows(): ?string
    {
        $output = @shell_exec('getmac /fo csv /nh 2>NUL');

        if ($output === null || $output === false || trim($output) === '') {
            return null;
        }

        return $output;
    }

    /**
     * discoverMacOs shells out to `ifconfig` and returns its raw stdout
     * output, or null if the command fails or produces no output.
     */
    private static function discoverMacOs(): ?string
    {
        $output = @shell_exec('ifconfig 2>/dev/null');

        if ($output === null || $output === false || trim($output) === '') {
            return null;
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Layer 2 — parsing (pure, fully testable)
    // -------------------------------------------------------------------------

    /**
     * parseAddresses is the shared parsing template used by all three
     * platform-specific parsers. It handles the common loop:
     *   - split raw output into units (lines or blocks)
     *   - call $extractCandidate on each unit to get a MAC string candidate
     *   - normalise and validate; return the first usable result
     *
     * @param callable(string): ?string $extractCandidate
     *        Returns a raw "xx:xx:xx:xx:xx:xx" candidate from one unit,
     *        or null if this unit should be skipped.
     */
    private static function parseAddresses(
        string $raw,
        string $splitPattern,
        callable $extractCandidate
    ): ?string {
        $units = preg_split($splitPattern, trim($raw));
        if ($units === false) {
            return null;
        }

        foreach ($units as $unit) {
            if ($unit === '') {
                continue;
            }

            $candidate = $extractCandidate($unit);
            if ($candidate === null) {
                continue;
            }

            $mac = self::normaliseMac($candidate);
            if (self::isUsableMac($mac)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * parseLinuxAddresses parses the "iface:address\n" format produced by
     * discoverLinux() and returns the first non-loopback, non-zero MAC
     * address in "xx:xx:xx:xx:xx:xx" form, or null.
     *
     * Pure function — fully testable with fixture strings.
     */
    public static function parseLinuxAddresses(string $raw): ?string
    {
        return self::parseAddresses($raw, '/\r?\n/', static function (string $line): ?string {
            $colon = strpos($line, ':');
            if ($colon === false) {
                return null;
            }

            $iface   = substr($line, 0, $colon);
            $address = trim(substr($line, $colon + 1));

            if ($iface === 'lo' || $address === '') {
                return null;
            }

            return $address;
        });
    }

    /**
     * parseWindowsAddresses parses the CSV output of `getmac /fo csv /nh`
     * and returns the first usable MAC address in "xx:xx:xx:xx:xx:xx" form
     * (normalised from Windows' "xx-xx-xx-xx-xx-xx" hyphenated format), or null.
     *
     * Pure function — fully testable with fixture strings.
     */
    public static function parseWindowsAddresses(string $raw): ?string
    {
        return self::parseAddresses($raw, '/\r?\n/', static function (string $line): ?string {
            // CSV format: "MAC-ADDRESS","Transport Name"
            $columns = str_getcsv($line);
            if ($columns[0] === '') {
                return null;
            }

            // Normalise Windows hyphen-separated format to colon-separated.
            return str_replace('-', ':', $columns[0]);
        });
    }

    /**
     * parseMacOsAddresses parses the output of `ifconfig` on macOS and
     * returns the first non-loopback adapter's "ether" address in
     * "xx:xx:xx:xx:xx:xx" form, or null.
     *
     * Pure function — fully testable with fixture strings.
     */
    public static function parseMacOsAddresses(string $raw): ?string
    {
        return self::parseAddresses($raw, '/^(?=\S)/m', static function (string $block): ?string {
            if (str_starts_with($block, 'lo')) {
                return null;
            }

            return preg_match('/ether\s+([0-9a-fA-F:]{17})/', $block, $matches)
                ? $matches[1]
                : null;
        });
    }

    // -------------------------------------------------------------------------
    // Layer 3 — normalisation and validation (pure, fully testable)
    // -------------------------------------------------------------------------

    /**
     * normaliseMac converts a "xx:xx:xx:xx:xx:xx" or "xx-xx-xx-xx-xx-xx"
     * candidate string into its raw 6-byte binary representation, or null
     * if the input is not a well-formed MAC address.
     */
    public static function normaliseMac(string $candidate): ?string
    {
        $normalised = str_replace('-', ':', $candidate);

        if (!preg_match('/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', $normalised)) {
            return null;
        }

        $hex = str_replace(':', '', $normalised);
        $bin = hex2bin($hex);

        return $bin === false ? null : $bin;
    }

    /**
     * isUsableMac returns true when $mac is a non-null, non-all-zero 6-byte
     * binary string — i.e. a real hardware MAC address worth using.
     */
    public static function isUsableMac(?string $mac): bool
    {
        return $mac !== null && $mac !== "\x00\x00\x00\x00\x00\x00";
    }

    /**
     * lastFourBytesAsUint32 interprets the last 4 bytes of a 6-byte MAC
     * address as a big-endian uint32, matching Go's
     * binary.BigEndian.Uint32(addr[len(addr)-4:]).
     */
    private static function lastFourBytesAsUint32(string $mac): int
    {
        $lastFour = substr($mac, -4);

        /** @var array{1:int} $unpacked */
        $unpacked = unpack('N', $lastFour);

        return $unpacked[1];
    }
}
