<?php
/**
 * WebAuthn registration fixtures for tests.
 *
 * Builds a real, fully-formed registration payload that the plugin verifies
 * end to end: clientDataJSON, an authenticatorData blob with valid RP ID hash
 * and flags, a COSE key, and a CBOR attestationObject using the "none"
 * attestation format. "none" performs no signature check, so no private key
 * material is needed and the fixture stays deterministic.
 */

defined('ABSPATH') || exit;

function sp_cbor_head(int $major, int $len): string {
    if ($len < 0) throw new InvalidArgumentException("sp_cbor_head: negative length $len");
    if ($len < 24)    return chr(($major << 5) | $len);
    if ($len < 256)   return chr(($major << 5) | 24) . chr($len);
    if ($len < 65536) return chr(($major << 5) | 25) . pack('n', $len);
    return chr(($major << 5) | 26) . pack('N', $len);
}
function sp_cbor_text(string $s): string  { return sp_cbor_head(3, strlen($s)) . $s; }
function sp_cbor_bytes(string $b): string { return sp_cbor_head(2, strlen($b)) . $b; }

/** Encode any integer, choosing major type 0 (>=0) or 1 (<0). */
function sp_cbor_int(int $v): string {
    return $v >= 0 ? sp_cbor_head(0, $v) : sp_cbor_head(1, -1 - $v);
}

/** Map from a list of [keyBytes, valueBytes] pairs, preserving order. */
function sp_cbor_map(array $pairs): string {
    $out = sp_cbor_head(5, count($pairs));
    foreach ($pairs as [$k, $v]) { $out .= $k . $v; }
    return $out;
}

/** COSE_Key, EC2 / P-256. Filler coordinates are fine: the "none"
 *  attestation format verifies no signature. */
function sp_cose_ec2_key(): string {
    return sp_cbor_map([
        [sp_cbor_int(1),  sp_cbor_int(2)],                    // kty: EC2
        [sp_cbor_int(3),  sp_cbor_int(-7)],                   // alg: ES256
        [sp_cbor_int(-1), sp_cbor_int(1)],                    // crv: P-256
        [sp_cbor_int(-2), sp_cbor_bytes(str_repeat("\x11", 32))],
        [sp_cbor_int(-3), sp_cbor_bytes(str_repeat("\x22", 32))],
    ]);
}

/**
 * Build a registration payload shaped like the plugin's JS POST body.
 * @return array{rawId:string,response:array{clientDataJSON:string,attestationObject:string}}
 */
function sp_make_none_attestation(string $challenge_b64url, string $origin, string $rp_id, string $cred_id): array {
    $clientData = json_encode([
        'type'      => 'webauthn.create',
        'challenge' => $challenge_b64url,
        'origin'    => $origin,
    ], JSON_UNESCAPED_SLASHES);

    // flags: UP (0x01) | UV (0x04) | AT (0x40)
    $flags   = chr(0x01 | 0x04 | 0x40);
    $counter = pack('N', 0);
    $aaguid  = str_repeat("\x00", 16);
    $credLen = pack('n', strlen($cred_id));
    $coseKey = sp_cose_ec2_key();

    $authData = hash('sha256', $rp_id, true) . $flags . $counter
              . $aaguid . $credLen . $cred_id . $coseKey;

    // { fmt: "none", attStmt: {}, authData: bytes }
    $attestationObject = sp_cbor_map([
        [sp_cbor_text('fmt'),      sp_cbor_text('none')],
        [sp_cbor_text('attStmt'),  sp_cbor_map([])],
        [sp_cbor_text('authData'), sp_cbor_bytes($authData)],
    ]);

    $b64url = fn(string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

    return [
        'rawId'    => $b64url($cred_id),
        'response' => [
            'clientDataJSON'    => $b64url($clientData),
            'attestationObject' => $b64url($attestationObject),
        ],
    ];
}
