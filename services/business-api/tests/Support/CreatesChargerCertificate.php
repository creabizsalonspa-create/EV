<?php

namespace Tests\Support;

trait CreatesChargerCertificate
{
    private function certificatePair(string $identity = 'ev-test-certificate', string $purpose = 'clientAuth', int $bits = 2048, int $issuerDays = 10, int $leafDays = 5): array
    {
        $directory = sys_get_temp_dir().'/charger-cert-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $config = $directory.'/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage={$purpose}\n");
        $rootKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $rootCsr = openssl_csr_new(['commonName' => 'Synthetic isolated charging CA'], $rootKey, ['config' => $config, 'digest_alg' => 'sha256']);
        $root = openssl_csr_sign($rootCsr, null, $rootKey, $issuerDays, ['config' => $config, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256'], random_int(1, 1000000));
        openssl_x509_export($root, $rootPem);
        $leafKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        $csr = openssl_csr_new(['commonName' => $identity], $leafKey, ['config' => $config, 'digest_alg' => 'sha256']);
        $leaf = openssl_csr_sign($csr, $root, $rootKey, $leafDays, ['config' => $config, 'x509_extensions' => 'client', 'digest_alg' => 'sha256'], random_int(1, 1000000));
        openssl_x509_export($leaf, $leafPem);
        $bundle = $directory.'/issuers.pem';
        file_put_contents($bundle, $rootPem);
        chmod($bundle, 0600);
        config(['csms.certificate_management_enabled' => true, 'csms.certificate_issuer_bundle' => $bundle, 'csms.certificate_issuer_pins' => [openssl_x509_fingerprint($rootPem, 'sha256')]]);
        $this->beforeApplicationDestroyed(function () use ($directory) {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        });

        return [$leafPem, $rootPem];
    }
}
