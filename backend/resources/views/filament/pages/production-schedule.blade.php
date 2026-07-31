@use('App\Filament\Resources\Orders\OrderResource')

<x-filament-panels::page>
    <style>
        .ps-days { display: flex; flex-direction: column; gap: 1.5rem; margin-top: 1.5rem; }
        .ps-range { display: flex; flex-wrap: wrap; gap: 1rem; align-items: end; margin-top: 1.5rem; }
        .ps-range label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.25rem; }
        .ps-range input { border: 1px solid rgba(120, 120, 120, 0.4); border-radius: 0.5rem; padding: 0.375rem 0.75rem; background: transparent; }
        .ps-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ps-table th { text-align: left; font-weight: 600; padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.3); }
        .ps-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid rgba(120, 120, 120, 0.15); vertical-align: top; }
        .ps-qty { font-weight: 700; white-space: nowrap; }
        .ps-orders summary { cursor: pointer; }
        .ps-orders a { text-decoration: underline; }
        .ps-empty { opacity: 0.6; font-size: 0.875rem; }

        /* Calendar — hand-styled to sit quietly in the panel, light and dark.
           FullCalendar injects its own base CSS from the vendored bundle; these
           custom properties recolor it with the same neutral rgba grays the
           list already uses, so both schemes work without a compiled theme. */
        #ps-calendar {
            font-size: 0.875rem;
            --fc-border-color: rgba(120, 120, 120, 0.25);
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: rgba(120, 120, 120, 0.08);
            --fc-today-bg-color: rgba(245, 158, 11, 0.09);
            --fc-event-bg-color: transparent;
            --fc-event-border-color: rgba(120, 120, 120, 0.5);
            --fc-event-text-color: inherit;
            --fc-button-text-color: inherit;
            --fc-button-bg-color: transparent;
            --fc-button-border-color: rgba(120, 120, 120, 0.4);
            --fc-button-hover-bg-color: rgba(120, 120, 120, 0.12);
            --fc-button-hover-border-color: rgba(120, 120, 120, 0.5);
            --fc-button-active-bg-color: rgba(120, 120, 120, 0.2);
            --fc-button-active-border-color: rgba(120, 120, 120, 0.5);
        }
        #ps-calendar .fc-toolbar-title { font-size: 1.125rem; font-weight: 600; }
        #ps-calendar .fc-button { text-transform: capitalize; box-shadow: none; }
        #ps-calendar .fc-daygrid-day { cursor: pointer; }
        #ps-calendar .fc-daygrid-day:hover { background: rgba(120, 120, 120, 0.06); }
        #ps-calendar .fc-daygrid-day-number { font-size: 0.8125rem; }
        /* the day's one aggregate entry, as a hairline pill */
        #ps-calendar .fc-event { padding: 0 0.5rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.02em; cursor: pointer; }

        .ps-day-anchor { scroll-margin-top: 5rem; }
        .ps-flash { outline: 2px solid rgba(245, 158, 11, 0.65); outline-offset: 3px; border-radius: 0.75rem; }
    </style>

    {{-- Month overview: one aggregate chip per day. The worklist below stays
         the source of per-fragrance detail — clicking a day jumps to its card. --}}
    <div wire:ignore>
        <x-filament::section>
            <div id="ps-calendar"></div>
        </x-filament::section>
    </div>

    <div class="ps-range">
        <div>
            <label for="ps-from">From</label>
            <input id="ps-from" type="date" wire:model.live="from" />
        </div>
        <div>
            <label for="ps-to">To</label>
            <input id="ps-to" type="date" wire:model.live="to" />
        </div>
    </div>

    <div class="ps-days">
        @foreach ($this->getDays() as $day)
            <div class="ps-day-anchor" id="ps-day-{{ $day['date']->toDateString() }}">
                <x-filament::section>
                    <x-slot name="heading">
                        {{ $day['date']->isToday() ? 'Today — ' : '' }}{{ $day['date']->format('l, j M Y') }}
                    </x-slot>

                    @if ($day['groups']->isEmpty())
                        <p class="ps-empty">Nothing to decant.</p>
                    @else
                        <table class="ps-table">
                            <thead>
                                <tr>
                                    <th>Fragrance</th>
                                    <th>Size</th>
                                    <th>Vials to fill</th>
                                    <th>Orders</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($day['groups'] as $group)
                                    <tr>
                                        <td>{{ $group['label'] }}</td>
                                        <td>{{ $group['size_ml'] }}ml</td>
                                        <td class="ps-qty">× {{ $group['quantity'] }}</td>
                                        <td class="ps-orders">
                                            <details>
                                                <summary>{{ $group['orders']->count() }} order(s)</summary>
                                                <ul>
                                                    @foreach ($group['orders'] as $order)
                                                        <li>
                                                            <a href="{{ OrderResource::getUrl('edit', ['record' => $order]) }}">
                                                                #{{ $order->id }} — {{ $order->customer_name }}
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-filament::section>
            </div>
        @endforeach
    </div>

    @assets
        <script src="{{ asset('vendor/fullcalendar/index.global.min.js') }}"></script>
    @endassets

    @script
        <script>
            const calendar = new FullCalendar.Calendar(document.getElementById('ps-calendar'), {
                initialView: 'dayGridMonth',
                headerToolbar: { left: 'title', right: 'today prev,next' },
                height: 'auto',
                fixedWeekCount: false,
                // Plain Y-m-d strings in both directions — see calendarEvents()
                // on the page class for why nothing here may carry a timezone.
                events: (info, success, failure) => {
                    $wire.calendarEvents(info.startStr, info.endStr).then(success).catch(failure);
                },
                dateClick: (info) => revealDay(info.dateStr),
                eventClick: (info) => revealDay(info.event.startStr),
            });

            calendar.render();

            function revealDay(date) {
                const card = document.getElementById('ps-day-' + date);

                if (card) {
                    flash(card);

                    return;
                }

                // Day outside the list window: focus the list on it, then jump.
                $wire.revealDay(date).then(() => requestAnimationFrame(() => {
                    const revealed = document.getElementById('ps-day-' + date);
                    if (revealed) flash(revealed);
                }));
            }

            function flash(card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                card.classList.add('ps-flash');
                setTimeout(() => card.classList.remove('ps-flash'), 1500);
            }
        </script>
    @endscript
</x-filament-panels::page>
