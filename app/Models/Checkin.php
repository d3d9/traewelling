<?php

namespace App\Models;

use App\Casts\UTCDateTime;
use App\Enum\TimeType;
use App\Http\Controllers\Backend\Transport\TrainCheckinController;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * //properties
 * @property int         $id
 * @property int         $status_id
 * @property int         $user_id
 * @property string      $trip_id
 * @property int         $origin      @deprecated -> use origin_stopover instead
 * @property int         $origin_stopover_id
 * @property int         $destination @deprecated -> use destination_stopover instead
 * @property int         $destination_stopover_id
 * @property int         $distance
 * @property int         $duration
 * @property UTCDateTime $departure   @deprecated -> use origin_stopover instead
 * @property UTCDateTime $manual_departure
 * @property UTCDateTime $arrival     @deprecated -> use destination_stopover instead
 * @property UTCDateTime $manual_arrival
 * @property int         $points
 * @property bool        $forced
 *
 * //relations
 * @property Trip        $trip
 * @property Status      $status
 * @property User        $user
 * @property Station     $originStation
 * @property Stopover    $originStopover
 * @property Station     $destinationStation
 * @property Stopover    $destinationStopover
 *
 * //appends
 * @property float       $speed
 * @property stdClass    $displayDeparture
 * @property stdClass    $displayArrival
 *
 * @todo rename table to "Checkin" (without Train - we have more than just trains)
 * @todo merge model with "Status" because the difference between trip sources (HAFAS,
 *        User, and future sources) should be handled in the Trip model.
 * @todo use the `id` from trips, instead of the hafas trip id - this is duplicated data
 * @todo drop the `origin`, `destination`, `departure` and `arrival` columns and use the stopover instead
 */
class Checkin extends Model
{

    use HasFactory;

    protected $table    = 'train_checkins';
    protected $fillable = [
        'status_id', 'user_id', 'trip_id', 'origin', 'origin_stopover_id', 'destination', 'destination_stopover_id',
        'distance', 'duration', 'duration_planned', 'departure', 'manual_departure', 'arrival', 'manual_arrival', 'points', 'forced',
    ];
    protected $hidden   = ['created_at', 'updated_at'];
    protected $appends  = ['speed', 'displayDeparture', 'displayArrival'];
    protected $casts    = [
        'id'                      => 'integer',
        'status_id'               => 'integer',
        'user_id'                 => 'integer',
        'origin'                  => 'integer', //@deprecated -> use origin_stopover_id instead
        'origin_stopover_id'      => 'integer',
        'destination'             => 'integer', //@deprecated -> use destination_stopover_id instead
        'destination_stopover_id' => 'integer',
        'distance'                => 'integer',
        'duration'                => 'integer',
        'duration_planned' => 'integer',
        'departure'               => UTCDateTime::class, //@deprecated -> use origin_stopover_id instead
        'manual_departure'        => UTCDateTime::class,
        'arrival'                 => UTCDateTime::class, //@deprecated -> use destination_stopover_id instead
        'manual_arrival'          => UTCDateTime::class,
        'points'                  => 'integer',
        'forced'                  => 'boolean',
    ];

    public function status(): BelongsTo {
        return $this->belongsTo(Status::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function originStation(): HasOne {
        return $this->hasOne(Station::class, 'ibnr', 'origin');
    }

    public function destinationStation(): HasOne {
        return $this->hasOne(Station::class, 'ibnr', 'destination');
    }

    /**
     * @return HasOne
     * @deprecated use ->trip instead
     */
    public function HafasTrip(): HasOne {
        return $this->trip();
    }

    public function trip(): HasOne {
        return $this->hasOne(Trip::class, 'trip_id', 'trip_id');
    }

    public function originStopover(): BelongsTo {
        return $this->belongsTo(Stopover::class, 'origin_stopover_id', 'id');
    }

    public function destinationStopover(): BelongsTo {
        return $this->belongsTo(Stopover::class, 'destination_stopover_id', 'id');
    }

    /**
     * Takes the planned and optionally real and manual times and returns an array to use while displaying the status.
     * Precedence: Manual > Real > Planned.
     * Only returns the 'original' planned time if the updated time differs from the planned time.
     */
    private static function prepareDisplayTime($planned, $real = null, $manual = null): array {
        if (isset($manual)) {
            $time = $manual;
            $type = TimeType::MANUAL;
        } elseif (isset($real)) {
            $time = $real;
            $type = TimeType::REALTIME;
        } else {
            $time = $planned;
            $type = TimeType::PLANNED;
        }
        return [
            'time'     => $time,
            'original' => ($planned->toString() !== $time->toString()) ? $planned : null,
            'type'     => $type
        ];
    }

    public function getDisplayDepartureAttribute(): stdClass {
        $planned = $this->originStopover?->departure_planned
                   ?? $this->originStopover?->departure
                      ?? $this->departure;
        $real    = $this->originStopover?->departure_real;
        $manual  = $this->manual_departure;
        $result  = self::prepareDisplayTime($planned, $real, $manual);
        $result['cancelled'] = $this->originStopover?->isDepartureCancelled;
        return (object) $result;
    }

    public function getDisplayArrivalAttribute(): stdClass {
        $planned = $this->destinationStopover?->arrival_planned
                   ?? $this->destinationStopover?->arrival
                      ?? $this->arrival;
        $real    = $this->destinationStopover?->arrival_real;
        $manual  = $this->manual_arrival;
        $result  = self::prepareDisplayTime($planned, $real, $manual);
        $result['cancelled'] = $this->destinationStopover?->isArrivalCancelled;
        return (object) $result;
    }

    /**
     * Overwrite the default getter to return the cached value if available
     * @return int
     */
    public function getDurationPlannedAttribute(): int {
        //If the duration is already calculated and saved, return it
        if (isset($this->attributes['duration_planned']) && $this->attributes['duration_planned'] !== null) {
            return $this->attributes['duration_planned'];
        }

        //Else calculate and cache it
        $departure = $this->originStopover->departure_planned ?? $this->departure;
        $arrival   = $this->destinationStopover->arrival_planned ?? $this->arrival;
        $duration_planned  = $arrival->diffInMinutes($departure);
        DB::table('train_checkins')->where('id', $this->id)->update(['duration_planned' => $duration_planned]);
        return $duration_planned;
    }

    /**
     * Overwrite the default getter to return the cached value if available
     * @return int
     */
    public function getDurationAttribute(): int {
        //If the duration is already calculated and saved, return it
        if (isset($this->attributes['duration']) && $this->attributes['duration'] !== null) {
            return $this->attributes['duration'];
        }

        //Else calculate and cache it
        return TrainCheckinController::calculateCheckinDuration($this);
    }

    public function getSpeedAttribute(): float {
        return $this->duration === 0 ? 0 : ($this->distance / 1000) / ($this->duration / 60);
    }

    /**
     * @return Collection
     * @todo Sichtbarkeit der CheckIns prüfen! Hier werden auch Private CheckIns angezeigt
     */
    public function getAlsoOnThisConnectionAttribute(): Collection {
        return new \Illuminate\Database\Eloquent\Collection(); // FGL. Checkins sind eh alle privat, aber bei eigenen überlappenden bekommt man sich selber angezeigt
        return self::with(['status'])
                   ->where([
                               ['status_id', '<>', $this->status->id],
                               ['trip_id', '=', $this->trip->trip_id],
                               ['arrival', '>', $this->departure],
                               ['departure', '<', $this->arrival]
                           ])
                   ->get()
                   ->map(function(Checkin $checkin) {
                       return $checkin->status;
                   })
                   ->filter(function($status) {
                       return $status !== null && Gate::forUser(Auth::user())->allows('view', $status);
                   });
    }
}
