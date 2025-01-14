<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Backend\EventController as EventBackend;
use App\Http\Controllers\Backend\Support\LocationController;
use App\Http\Controllers\Backend\User\DashboardController;
use App\Http\Controllers\Backend\User\ProfilePictureController;
use App\Http\Controllers\StatusController as StatusBackend;
use App\Models\Event;
use App\Models\TravelChain;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * @deprecated Content will be moved to the backend/frontend/API packages soon, please don't add new functions here!
 */
class FrontendStatusController extends Controller
{
    public function getDashboard(): Renderable|RedirectResponse {
        // $statuses = DashboardController::getPrivateDashboard(auth()->user());

        $noChain = StatusBackend::getNoChainCheckins();

        $ctaStatuses = StatusBackend::getCtaCheckins();

        $user_id = auth()->user()->id;

        $counts = ['statuses', 'plannedStatuses', 'takenStatuses', 'pendingStatuses', 'undefStatuses'];

        $chainsInProgress = TravelChain::withCount($counts)
            ->where('user_id', $user_id)
            ->whereNull('finished')
            ->latest()
            ->get();

        $finishedChains = TravelChain::withCount($counts)
            ->where('user_id', $user_id)
            ->whereNotNull('finished')
            ->latest()
            ->paginate(5);

        /*
        if ($statuses->isEmpty() || auth()->user()->follows->count() === 0) {
            if (Session::has('checkin-success')) {
                return redirect()->route('globaldashboard')
                                 ->with('checkin-success', Session::get('checkin-success'));
            }
            if (Session::has('error')) {
                return redirect()->route('globaldashboard')
                                 ->with('error', Session::get('error'));
            }
            if (Session::has('checkin-collision')) {
                return redirect()->route('globaldashboard')
                                 ->with('checkin-collision', Session::get('checkin-collision'));
            }
            return redirect()->route('globaldashboard');
        }
        */
        return view('dashboard', [
            // 'statuses' => $statuses,
            'ctaStatuses' => $ctaStatuses,
            'noChain' => $noChain,
            'chainsInProgress' => $chainsInProgress,
            'finishedChains' => $finishedChains,
            'latest'   => TransportController::getLatestArrivals(auth()->user()),
            // 'future'   => StatusBackend::getFutureCheckins()
        ]);
    }

    public function getGlobalDashboard(): Renderable {
        return view('dashboard', [
            'statuses' => DashboardController::getGlobalDashboard(Auth::user()),
            'latest'   => TransportController::getLatestArrivals(Auth::user()),
            'future'   => StatusBackend::getFutureCheckins()
        ]);
    }

    public function getActiveStatuses(): Renderable {
        $activeEvents           = EventBackend::activeEvents();
        return view('activejourneys', [
            'currentUser' => Auth::user(),
            'statuses'    => StatusBackend::getActiveStatuses(),
            'events'      => $activeEvents,
            'event'       => null
        ]);
    }

    public function statusesByEvent(string $slug): Renderable {
        $event    = Event::where('slug', $slug)->firstOrFail();
        $response = StatusController::getStatusesByEvent($event);

        if ($response['event']->end->isPast() && $response['statuses']->count() === 0) {
            abort(404);
        }

        return view('eventsMap', [
            'statuses' => $response['statuses']->simplePaginate(15),
            'event'    => $response['event']
        ]);
    }

    public function getStatus($statusId): Renderable {
        $status = StatusBackend::getStatus($statusId);

        try {
            $this->authorize('view', $status);
        } catch (AuthorizationException) {
            /*session(["extraLink" => [
                        'url'  => route('profile', ['username' => $status->user->username]),
                        'text' => "@" . $status->user->username]]
            );*/
            abort(403, __('error.status.not-authorized'));
        }

        return view('status', [
            'status'      => $status,
            'time'        => time(),
            'title'       => __('status.ogp-title', ['name' => $status->user->username]),
            'description' => trans_choice('status.ogp-description', preg_match('/\s/', $status->checkin->trip->linename), [
                'linename'    => $status->checkin->trip->linename,
                'distance'    => number($status->checkin->distance / 1000, 1),
                'destination' => $status->checkin->destinationStation->name,
                'origin'      => $status->checkin->originStation->name
            ]),
            'image'       => ProfilePictureController::getUrl($status->user),
        ]);
    }
}
