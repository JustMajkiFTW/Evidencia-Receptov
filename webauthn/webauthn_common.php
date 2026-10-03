<?php
/**
 * webauthn_common.php — spoločné veci pre biometrické prihlasovanie (passkeys).
 *
 * POZOR: postavené na "web-auth/webauthn-lib" verzia ^5.3 (composer.json
 * v koreni appky to pripne). Ak composer nainštaluje inú major verziu,
 * pár názvov tried/metód sa môže mierne líšiť — appka už má vlastný
 * error handler (includes/config.php), ktorý PRESNÚ chybu zapíše do
 * debug-log.txt v koreni appky. Ak biometria hodí chybu, pozri sa tam.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredentialRpEntity;

function webauthn_serializer(): \Symfony\Component\Serializer\SerializerInterface
{
    static $serializer = null;
    if ($serializer === null) {
        $serializer = (new WebauthnSerializerFactory(AttestationStatementSupportManager::create()))->create();
    }
    return $serializer;
}

function webauthn_rp_entity(): PublicKeyCredentialRpEntity
{
    return PublicKeyCredentialRpEntity::create(WEBAUTHN_RP_NAME, WEBAUTHN_RP_ID, null);
}

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return (string) base64_decode($data);
}
