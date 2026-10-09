<?php

declare(strict_types=1);

namespace Maguari\Server\Http\Controller;

use Maguari\Server\Fleet\FleetApi;
use Maguari\Server\Http\AdminApiError;
use Maguari\Server\Http\AdminApiResponse;
use Maguari\Server\Http\JsonResponse;
use Maguari\Server\Http\QueryParameters;
use Maguari\Server\Monitoring\Exception\InvalidRunQuery;
use Maguari\Server\Monitoring\MonitoringApi;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One metric's stored runs as JSON, for charts (design sections 9.1 and
 * 10.1). Reads only SQLite.
 */
final class RunsController
{
    public function __construct(
        private readonly FleetApi $fleet,
        private readonly MonitoringApi $monitoring,
    ) {
    }

    /**
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Like the instance page: runs kept from an earlier pick are not shown.
        $instance = $this->fleet->pickedInstance((int) $args['id']);

        if ($instance === null) {
            return AdminApiResponse::error($response, AdminApiError::NotFound);
        }

        try {
            $series = $this->monitoring->runs($instance->id, QueryParameters::all($request->getUri()->getQuery()));
        } catch (InvalidRunQuery $invalid) {
            return AdminApiResponse::error($response, AdminApiError::BadRequest, $invalid->getMessage());
        }

        $runs = [];

        foreach ($series->runs as $run) {
            $runs[] = [$run->startAt, $run->endAt, $run->value];
        }

        return JsonResponse::write($response, [
            'instance_id' => $instance->id,
            'metric' => $series->query->metric,
            'from' => $series->query->from,
            'to' => $series->query->to,
            'max_gap_seconds' => $series->maxGapSeconds,
            'truncated' => $series->truncated,
            'columns' => ['start_at', 'end_at', 'value'],
            'runs' => $runs,
        ]);
    }
}
