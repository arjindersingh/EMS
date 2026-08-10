<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/public_layout.php';

$sliderDir = __DIR__ . '/assets/images/slider';
$sliderImages = [];

if (is_dir($sliderDir)) {
    foreach (scandir($sliderDir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        if (is_file($sliderDir . '/' . $file)) {
            $sliderImages[] = buildUrl('assets/images/slider/' . $file);
        }
    }
}

$openEventItineraries = [];
$openEventSchedules = [];

try {
    $homePdo = isset($pdo) && $pdo instanceof PDO ? $pdo : createDbConnection();
    $statement = $homePdo->query(
        "SELECT ei.*, e.event_title
         FROM event_itineraries ei
         INNER JOIN events e ON e.event_id = ei.event_id
         WHERE e.event_status = 'Open'
         ORDER BY e.start_date ASC, e.event_title ASC, ei.itinerary_date ASC, ei.start_time ASC"
    );
    $openEventItineraries = $statement ? $statement->fetchAll() : [];

    $statement = $homePdo->query(
        "SELECT es.*, e.event_title
         FROM event_schedules es
         INNER JOIN events e ON e.event_id = es.event_id
         WHERE e.event_status = 'Open'
         ORDER BY e.start_date ASC, e.event_title ASC, es.schedule_start_date ASC"
    );
    $openEventSchedules = $statement ? $statement->fetchAll() : [];
} catch (PDOException $exception) {
    $openEventItineraries = [];
    $openEventSchedules = [];
}

function formatHomeEventDate(?string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string) $date);
    return $parsed ? $parsed->format('d, M, y') : (string) $date;
}

function formatHomeEventTime(?string $time): string
{
    if (empty($time)) {
        return '—';
    }
    $parsed = DateTimeImmutable::createFromFormat('H:i:s', (string) $time)
        ?: DateTimeImmutable::createFromFormat('H:i', (string) $time);
    return $parsed ? $parsed->format('h:i A') : (string) $time;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Discover, register for, and enjoy campus events with EMS.">
    <title>EMS | Campus Events</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(buildUrl('assets/css/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="public-page home-page">
    <?php renderPublicHeader('home'); ?>

    <main>
        <div class="home-slider-card">
            <?php if (!empty($sliderImages)): ?>
                <div class="carousel" id="heroCarousel" aria-label="Featured event photos">
                    <?php foreach ($sliderImages as $index => $image): ?>
                        <img src="<?php echo htmlspecialchars($image, ENT_QUOTES, 'UTF-8'); ?>"
                             alt="Campus event highlight <?php echo $index + 1; ?>"
                             class="<?php echo $index === 0 ? 'active' : ''; ?>">
                    <?php endforeach; ?>
                    <div class="photo-label"><span>●</span> Happening now</div>
                    <div class="carousel-dots" aria-hidden="true">
                        <?php foreach ($sliderImages as $index => $_): ?>
                            <span class="<?php echo $index === 0 ? 'active' : ''; ?>"></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="caption">Featured Events</div>
                </div>
            <?php endif; ?>
            <div class="content">
                <p><a href="<?php echo htmlspecialchars(buildUrl('register'), ENT_QUOTES, 'UTF-8'); ?>">Register for Event</a></p>
            </div>
        </div>

        <section class="home-event-information">
            <div class="home-info-heading">
                <p class="eyebrow">Open events</p>
            </div>
            <div class="event-information-sections">
                <section class="event-information-panel itinerary-color-panel">
                    <div class="event-information-heading"><h2>Event-wise Itinerary</h2></div>
                    <?php if ($openEventItineraries): ?>
                        <div class="public-info-list">
                        <?php foreach ($openEventItineraries as $item): ?>
                            <article class="public-info-item">
                                <div class="public-info-meta">
                                    <strong><?php echo htmlspecialchars(formatHomeEventDate($item['itinerary_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars(formatHomeEventTime($item['start_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?> – <?php echo htmlspecialchars(formatHomeEventTime($item['end_time'] ?? null), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div>
                                    <span class="public-event-name"><?php echo htmlspecialchars((string) ($item['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <h3><?php echo htmlspecialchars((string) ($item['activity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <?php if (!empty($item['resource_person'])): ?><p class="resource-person">Resource: <?php echo htmlspecialchars((string) $item['resource_person'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                    <?php if (!empty($item['itinerary_description'])): ?><p><?php echo nl2br(htmlspecialchars((string) $item['itinerary_description'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="public-info-empty">No itinerary has been published for open events.</p><?php endif; ?>
                </section>

                <section class="event-information-panel schedule-color-panel">
                    <div class="event-information-heading"><span>Registration windows</span><h2>Registration Schedule</h2></div>
                    <?php if ($openEventSchedules): ?>
                        <div class="public-info-list">
                        <?php foreach ($openEventSchedules as $schedule): ?>
                            <article class="public-info-item">
                                <div class="public-info-meta">
                                    <strong><?php echo htmlspecialchars(formatHomeEventDate($schedule['schedule_start_date'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span>to <?php echo !empty($schedule['schedule_end_date']) ? htmlspecialchars(formatHomeEventDate($schedule['schedule_end_date']), ENT_QUOTES, 'UTF-8') : 'Open ended'; ?></span>
                                </div>
                                <div>
                                    <span class="public-event-name"><?php echo htmlspecialchars((string) ($schedule['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <h3>Registration Window</h3>
                                    <p><?php echo nl2br(htmlspecialchars((string) ($schedule['schedule_description'] ?? 'Registration is available during this period.'), ENT_QUOTES, 'UTF-8')); ?></p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="public-info-empty">No registration schedules have been published for open events.</p><?php endif; ?>
                </section>
            </div>
        </section>
    </main>

    <?php renderPublicFooter(); ?>

    <script>
        (function () {
            const carousel = document.getElementById('heroCarousel');
            if (!carousel) return;

            const slides = carousel.querySelectorAll('img');
            const dots = carousel.querySelectorAll('.carousel-dots span');
            let current = 0;
            if (slides.length <= 1) return;

            setInterval(function () {
                slides[current].classList.remove('active');
                dots[current].classList.remove('active');
                current = (current + 1) % slides.length;
                slides[current].classList.add('active');
                dots[current].classList.add('active');
            }, 4000);
        })();
    </script>
</body>
</html>
