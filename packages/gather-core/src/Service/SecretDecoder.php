<?php

namespace Fagathe\GatherCore\Service;

/**
 * Décode les secrets (mots de passe) envoyés en base64 par le frontend.
 *
 * Le décodage doit toujours avoir lieu AVANT la validation et le hash/vérification :
 * les contraintes (longueur, complexité) portent sur la valeur réelle, pas sur son encodage.
 */
final class SecretDecoder
{
    /**
     * Décode une valeur base64 (UTF-8). Retourne null si la valeur est invalide.
     */
    public function decode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // Mode strict : rejette les caractères hors alphabet base64
        $decoded = base64_decode($value, true);

        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return $decoded;
    }

    /**
     * Retourne le JSON du body avec les champs indiqués décodés, prêt pour le serializer.
     * Retourne null si le JSON est invalide ou si un champ présent n'est pas du base64 valide.
     * Les champs absents ou null sont laissés tels quels (la validation du DTO s'en charge).
     *
     * @param string[] $fields
     */
    public function decodeJsonBody(string $json, array $fields): ?string
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $decoded = $this->decode($data[$field]);
            if ($decoded === null) {
                return null;
            }

            $data[$field] = $decoded;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
