<?php
require_once __DIR__ . '/webauthn_common.php';
require_login(); // len prihlásený používateľ si môže pridať biometriu

header('Content-Type: application/json; charset=utf-8');

use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\AuthenticatorSelectionCriteria;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

try {
    $user = current_user();

    $stmt = $pdo->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $excludeCredentials = array_map(
        static fn(array $row) => PublicKeyCredentialDescriptor::create('public-key', b64url_decode($row['credential_id'])),
        $stmt->fetchAll()
    );

    $userEntity = PublicKeyCredentialUserEntity::create(
        $user['username'],
        (string) $user['id'],
        $user['full_name'] ?: $user['username']
    );

    $creationOptions = PublicKeyCredentialCreationOptions::create(
        webauthn_rp_entity(),
        $userEntity,
        random_bytes(32),
        excludeCredentials: $excludeCredentials,
        authenticatorSelection: AuthenticatorSelectionCriteria::create(
            // „platform" = kľúč sa uloží do TOHTO zariadenia a odomyká sa jeho
            // odtlačkom / Face ID. Bez toho prehliadač ponúka aj USB kľúče a iné
            // telefóny, čo pri prihlasovaní pridáva obrazovku navyše.
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
        ),
        attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
    );

    $_SESSION['webauthn_registration_options'] = webauthn_serializer()->serialize($creationOptions, 'json');
    $_SESSION['webauthn_registration_user_id'] = $user['id'];

    echo webauthn_serializer()->serialize($creationOptions, 'json', [
        AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
    ]);
} catch (Throwable $e) {
    debug_log('WEBAUTHN REGISTER OPTIONS ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Nepodarilo sa pripraviť registráciu biometrie.']);
}
