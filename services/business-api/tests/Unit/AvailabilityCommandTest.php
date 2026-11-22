<?php

namespace Tests\Unit;

use App\Services\Charging\AvailabilityCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class AvailabilityCommandTest extends TestCase
{
    public function test_station_and_component_targets_use_the_matching_protocol_schema(): void
    {
        self::assertSame(['connectorId' => 0, 'type' => 'Inoperative'], AvailabilityCommand::payload('1.6', 'Inoperative'));
        self::assertSame(['connectorId' => 4, 'type' => 'Operative'], AvailabilityCommand::payload('1.6', 'Operative', connector: 4));
        self::assertSame(['operationalStatus' => 'Operative'], AvailabilityCommand::payload('2.0.1', 'Operative'));
        self::assertSame(['operationalStatus' => 'Inoperative', 'evse' => ['id' => 3]], AvailabilityCommand::payload('2.0.1', 'Inoperative', 3));
        self::assertSame(['operationalStatus' => 'Inoperative', 'evse' => ['id' => 3, 'connectorId' => 2]], AvailabilityCommand::payload('2.0.1', 'Inoperative', 3, 2));
    }

    #[TestWith(['1.6', 'Operative', null, 0])]
    #[TestWith(['1.6', 'Operative', 1, 2])]
    #[TestWith(['2.0.1', 'Operative', null, 2])]
    #[TestWith(['2.0.1', 'Operative', 0, 2])]
    #[TestWith(['2.0.1', 'Available', null, null])]
    public function test_invalid_addresses_and_states_cannot_fall_back_to_station_scope(string $protocol, string $state, ?int $evse, ?int $connector): void
    {
        $this->expectException(InvalidArgumentException::class);
        AvailabilityCommand::payload($protocol, $state, $evse, $connector);
    }

    public function test_unrecognized_or_transport_only_results_do_not_become_confirmations(): void
    {
        self::assertSame('accepted', AvailabilityCommand::confirmation('Accepted'));
        self::assertSame('rejected', AvailabilityCommand::confirmation('Rejected'));
        self::assertSame('scheduled', AvailabilityCommand::confirmation('Scheduled'));
        self::assertSame('outcome_unknown', AvailabilityCommand::confirmation(['success' => true]));
    }
}
