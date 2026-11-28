<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\DB;

final class DirectConnectorIdentity
{
    /** The caller must hold the connector row lock in this transaction. */
    public static function fingerprint(object $connector): string
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Connector identity requires a locked transaction.');
        $generation = DB::table('direct_connector_identity_changes')->where('connector_id', $connector->id)->max('id');

        return DirectConnectorReviewCoverage::connectorFingerprint($connector, (int) $generation);
    }
}
