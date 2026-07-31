@use('App\Filament\Pages\ProductionScheduleDay')

<x-filament-panels::page>
    <style>
        /* Calendar — hand-styled to sit quietly in the panel, light and dark.
           FullCalendar injects its own base CSS from the vendored bundle; these
           custom properties recolor it with the same neutral rgba grays the
           panel already uses, so both schemes work without a compiled theme.
           Text colors must be explicit values: a CSS-wide keyword (`inherit`)
           on a custom property never substitutes through var() — it makes the
           property itself inherit, which resolves to FullCalendar's :root
           default of white, i.e. invisible chips in light mode. */
        #ps-calendar {
            font-size: 0.875rem;
            --fc-border-color: rgba(120, 120, 120, 0.25);
            --fc-page-bg-color: transparent;
            --fc-neutral-bg-color: rgba(120, 120, 120, 0.08);
            --fc-today-bg-color: rgba(245, 158, 11, 0.09);
            --fc-event-bg-color: transparent;
            --fc-event-border-color: rgba(120, 120, 120, 0.5);
            --fc-event-text-color: rgb(31, 41, 55);
            --fc-button-text-color: rgb(75, 85, 99);
            --fc-button-bg-color: transparent;
            --fc-button-border-color: rgba(120, 120, 120, 0.4);
            --fc-button-hover-bg-color: rgba(120, 120, 120, 0.12);
            --fc-button-hover-border-color: rgba(120, 120, 120, 0.5);
            --fc-button-active-bg-color: rgba(120, 120, 120, 0.2);
            --fc-button-active-border-color: rgba(120, 120, 120, 0.5);
        }
        .dark #ps-calendar {
            --fc-border-color: rgba(255, 255, 255, 0.12);
            --fc-neutral-bg-color: rgba(255, 255, 255, 0.06);
            --fc-today-bg-color: rgba(245, 158, 11, 0.12);
            --fc-event-border-color: rgba(255, 255, 255, 0.35);
            --fc-event-text-color: rgb(229, 231, 235);
            --fc-button-text-color: rgb(209, 213, 219);
            --fc-button-border-color: rgba(255, 255, 255, 0.25);
            --fc-button-hover-bg-color: rgba(255, 255, 255, 0.08);
            --fc-button-hover-border-color: rgba(255, 255, 255, 0.35);
            --fc-button-active-bg-color: rgba(255, 255, 255, 0.16);
            --fc-button-active-border-color: rgba(255, 255, 255, 0.35);
        }
        #ps-calendar .fc-toolbar-title { font-size: 1.125rem; font-weight: 600; }
        #ps-calendar .fc-button { text-transform: capitalize; box-shadow: none; }
        #ps-calendar .fc-daygrid-day { cursor: pointer; }
        #ps-calendar .fc-daygrid-day:hover { background: rgba(120, 120, 120, 0.06); }
        .dark #ps-calendar .fc-daygrid-day:hover { background: rgba(255, 255, 255, 0.04); }
        #ps-calendar .fc-daygrid-day-number { font-size: 0.8125rem; }
        /* the day's one aggregate entry, as a hairline pill */
        #ps-calendar .fc-event { padding: 0 0.5rem; border-radius: 9999px; font-weight: 600; font-size: 0.75rem; letter-spacing: 0.02em; cursor: pointer; }
        /* overdue: behind us and still not fully poured — not history */
        #ps-calendar .fc-event.ps-overdue { border-color: rgba(220, 38, 38, 0.6); --fc-event-text-color: rgb(185, 28, 28); }
        .dark #ps-calendar .fc-event.ps-overdue { border-color: rgba(248, 113, 113, 0.55); --fc-event-text-color: rgb(248, 113, 113); }
    </style>

    {{-- The month overview is the whole page: one aggregate chip per busy day,
         and every day cell — chip or empty — clicks through to that day's
         worklist at /admin/production-schedule/{date}. wire:ignore keeps any
         future Livewire morph from replacing FullCalendar's DOM. --}}
    <div wire:ignore>
        <x-filament::section>
            <div id="ps-calendar"></div>
        </x-filament::section>
    </div>

    @assets
        <script src="{{ asset('vendor/fullcalendar/index.global.min.js') }}"></script>
    @endassets

    @script
        <script>
            // Every day opens its own worklist page — empty days included, so
            // the decanter can print tomorrow's (blank) sheet or step from it.
            const dayUrl = (date) => @js(ProductionScheduleDay::getUrl(['date' => '__DATE__'])).replace('__DATE__', date);

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
                dateClick: (info) => window.location.assign(dayUrl(info.dateStr)),
                eventClick: (info) => window.location.assign(dayUrl(info.event.startStr)),
            });

            calendar.render();
        </script>
    @endscript
</x-filament-panels::page>
