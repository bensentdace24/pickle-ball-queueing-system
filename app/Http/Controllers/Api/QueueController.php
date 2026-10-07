<?php

namespace App\Http\Controllers\Api;

use App\Enums\QueueStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQueueRequest;
use App\Http\Resources\QueueResource;
use App\Http\Traits\ApiResponse;
use App\Models\Player;
use App\Models\Queue;
use App\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class QueueController extends Controller
{
    use ApiResponse;

    public function __construct(private QueueService $queues) {}

    // Active queue (waiting + called) in join order, with position.
    public function index()
    {
        $entries = $this->queues->activeQueue();
        $entries->each(fn($e, $i) => $e->setAttribute('position', $i + 1));

        return $this->success(QueueResource::collection($entries));
    }

    // Join queue. Accepts an existing player_id, or name/phone/skill_level to register + join.
    public function store(StoreQueueRequest $request)
    {
        $data = $request->validated();

        $queue = DB::transaction(function () use ($data) {
            $player = isset($data['player_id'])
                ? Player::findOrFail($data['player_id'])
                : Player::create(Arr::only($data, ['name', 'phone', 'skill_level']));

            return $this->queues->join($player, $data['match_type'] ?? 'any', false);
        });

        $queue->load('player');

        return $this->success(
            new QueueResource($this->withPosition($queue)),
            'Joined the queue.',
            201
        );
    }

    public function selfRegister(StoreQueueRequest $request)
    {
        $data = $request->validated();

        $queue = DB::transaction(function () use ($data) {
            $player = isset($data['player_id'])
                ? Player::findOrFail($data['player_id'])
                : Player::create(Arr::only($data, ['name', 'phone', 'skill_level']));

            return $this->queues->join($player, $data['match_type'] ?? 'any', true);
        });

        $queue->load('player');

        return $this->success(
            new QueueResource($queue),
            'Registered — please see the front desk to confirm your spot.',
            201
        );
    }

    public function pending()
    {
        return $this->success(QueueResource::collection($this->queues->pendingList()));
    }

    public function approve(Queue $queue)
    {
        $queue = $this->queues->approve($queue);

        return $this->success(new QueueResource($this->withPosition($queue)), 'Approved — added to the queue.');
    }

    public function reject(Queue $queue)
    {
        $queue = $this->queues->reject($queue);

        return $this->success(new QueueResource($queue), 'Registration rejected.');
    }

    // Player-facing status: position, status, assigned court and game.
    public function show(Queue $queue)
    {
        $queue->load('player', 'gamePlayer.game.court', 'gamePlayer.game.gamePlayers.player');

        return $this->success(new QueueResource($this->withPosition($queue)));
    }

    public function cancel(Queue $queue)
    {
        $queue = $this->queues->cancel($queue);

        return $this->success(new QueueResource($queue->load('player')), 'Queue entry cancelled.');
    }

    public function callNext(Request $request)
    {
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $called = $this->queues->callNext($data['count'] ?? 4);

        if ($called->isEmpty()) {
            throw new BusinessRuleException('No waiting players to call.');
        }

        return $this->success(
            QueueResource::collection($called->load('player')),
            'Players called.'
        );
    }

    private function withPosition(Queue $queue): Queue
    {
        $position = null;

        if (in_array($queue->status, [QueueStatus::Waiting->value, QueueStatus::Called->value])) {
            $position = Queue::whereIn('status', [QueueStatus::Waiting->value, QueueStatus::Called->value])
                ->where(function ($q) use ($queue) {
                    $q->where('joined_at', '<', $queue->joined_at)
                        ->orWhere(function ($q2) use ($queue) {
                            $q2->where('joined_at', $queue->joined_at)
                                ->where('queue_number', '<', $queue->queue_number);
                        });
                })
                ->count() + 1;
        }

        $queue->setAttribute('position', $position);

        return $queue;
    }
}
