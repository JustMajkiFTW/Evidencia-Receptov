<?php
require_once __DIR__ . '/webauthn_common.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

use Webauthn\PublicKeyCredential;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredentialCreationOptions;

try {
    $user = current_user();

    if (empty($_SESSION['webauthn_registration_options'])
        || (int) ($_SESSION['webauthn_registration_user_id'] ?? 0) !== (int) $user['id']) {
        throw new RuntimeException('Registračná session vypršala, skús to znova.');
    }

    $creationOptions = webauthn_serializer()->deserialize(
        $_SESSION['webauthn_registration_options'],
        PublicKeyCredentialCreationOptions::class,
        'json'
    );

    $raw = file_get_contents('php://input');
    $publicKeyCredential = webauthn_serializer()->deserialize($raw, PublicKeyCredential::class, 'json');

    if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
        throw new RuntimeException('Neplatný typ odpovede z prehliadača.');
    }

    $csmFactory = new CeremonyStepManagerFactory();
    $validator = AuthenticatorAttestationResponseValidator::create($csmFactory->creationCeremony());

    $credentialRecord = $validator->check(
        $publicKeyCredential->response,
        $creationOptions,
        WEBAUTHN_ORIGIN
    );

    $credentialIdB64 = b64url_encode($credentialRecord->publicKeyCredentialId);
    $recordJson = webauthn_serializer()->serialize($credentialRecord, 'json');
    $label = trim((string) ($_GET['label'] ?? '')) ?: 'Odtlačok / Face ID';

    $stmt = $pdo->prepare(
        'INSERT INTO webauthn_credentials (user_id, credential_id, credential_record, label) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$user['id'], $credentialIdB64, $recordJson, $label]);

    unset($_SESSION['webauthn_registration_options'], $_SESSION['webauthn_registration_user_id']);

    // username si prehliadač zapamätá, aby nabudúce vedel rovno vyvolať biometriu
    echo json_encode(['success' => true, 'username' => $user['username']]);
} catch (Throwable $e) {
    debug_log('WEBAUTHN REGISTER VERIFY ERROR: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nepodarilo sa uložiť biometriu: ' . $e->getMessage()]);
}
