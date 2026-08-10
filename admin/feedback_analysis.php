<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/feedback_funcs.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_authenticated'])) { header('Location: ' . buildUrl('admin')); exit; }

$pdo = createDbConnection();
ensureFeedbackTables($pdo);
$events = $pdo->query("SELECT event_id, event_title, start_date FROM events ORDER BY start_date DESC, event_title")->fetchAll() ?: [];
$types = getFeedbackTypes($pdo, false);
$eventId = (int) ($_GET['event_id'] ?? 0);
$typeId = (int) ($_GET['feedback_type_id'] ?? 0);
$category = (string) ($_GET['category'] ?? 'designation');
$categories = ['designation' => 'Designation', 'approval_status' => 'Status', 'district' => 'District', 'state' => 'State'];
if (!isset($categories[$category])) $category = 'designation';
if ($eventId <= 0 && $events) $eventId = (int) $events[0]['event_id'];
if ($typeId <= 0 && $types) $typeId = (int) $types[0]['feedback_type_id'];

$event = null;
foreach ($events as $option) if ((int) $option['event_id'] === $eventId) { $event = $option; break; }
$selectedType = null;
foreach ($types as $option) if ((int) $option['feedback_type_id'] === $typeId) { $selectedType = $option; break; }

$invitationStats = ['invited' => 0, 'sent' => 0, 'submitted' => 0];
$itemStats = [];
$breakdown = [];
$qualitativeResponses = [];
$qualitativeAnalysis = ['response_count' => 0, 'columns' => []];
$overallPercentage = 0.0;
$ratedAnswers = 0;

if ($eventId > 0 && $typeId > 0) {
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) AS invited,
               SUM(status IN ('sent','submitted')) AS sent,
               SUM(status = 'submitted') AS submitted
        FROM feedback_invitations
        WHERE event_id = :event_id AND feedback_type_id = :type_id
SQL);
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    $invitationStats = array_map('intval', $statement->fetch() ?: $invitationStats);

    $statement = $pdo->prepare(<<<'SQL'
        SELECT fitem.feedback_item_id, fitem.item_text, fitem.response_type,
               COUNT(fr.rating) AS rating_count, AVG(fr.rating) AS average_rating,
               COUNT(NULLIF(TRIM(fr.response_text), '')) AS text_count
        FROM feedback_items fitem
        LEFT JOIN feedback_invitations fi
          ON fi.feedback_type_id = fitem.feedback_type_id AND fi.event_id = :event_id
        LEFT JOIN feedback_responses fr
          ON fr.feedback_id = fi.feedback_id AND fr.feedback_item_id = fitem.feedback_item_id
        WHERE fitem.feedback_type_id = :type_id AND fitem.is_active = 1
        GROUP BY fitem.feedback_item_id, fitem.item_text, fitem.response_type
        ORDER BY fitem.sort_order, fitem.feedback_item_id
SQL);
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    $itemStats = $statement->fetchAll() ?: [];
    $ratingTotal = 0.0;
    foreach ($itemStats as &$item) {
        $count = (int) $item['rating_count'];
        $average = $item['average_rating'] !== null ? (float) $item['average_rating'] : 0.0;
        $item['percentage'] = $count > 0 ? $average * 20 : 0.0;
        $ratingTotal += $average * $count;
        $ratedAnswers += $count;
    }
    unset($item);
    $overallPercentage = $ratedAnswers > 0 ? ($ratingTotal / $ratedAnswers) * 20 : 0.0;

    $categorySql = "COALESCE(NULLIF(TRIM(er.{$category}), ''), 'Not specified')";
    $statement = $pdo->prepare(<<<SQL
        SELECT {$categorySql} AS category_value,
               COUNT(DISTINCT fi.feedback_id) AS respondents,
               COUNT(fr.rating) AS rating_count,
               AVG(fr.rating) * 20 AS percentage
        FROM feedback_invitations fi
        JOIN event_registrations er ON er.registration_id = fi.registration_id
        LEFT JOIN feedback_responses fr ON fr.feedback_id = fi.feedback_id AND fr.rating IS NOT NULL
        WHERE fi.event_id = :event_id AND fi.feedback_type_id = :type_id AND fi.status = 'submitted'
        GROUP BY {$categorySql}
        ORDER BY percentage DESC, category_value
SQL);
    $statement->execute([':event_id' => $eventId, ':type_id' => $typeId]);
    $breakdown = $statement->fetchAll() ?: [];
    $qualitativeResponses = getFeedbackQualitativeResponses($pdo, $eventId, $typeId);
    $qualitativeAnalysis = analyseQualitativeFeedback($qualitativeResponses);
}

$responseRate = $invitationStats['invited'] > 0 ? ($invitationStats['submitted'] / $invitationStats['invited']) * 100 : 0;
$eventDisplay = $event ? $event['event_title'] . (!empty($event['start_date']) ? ' (' . date('d M, y', strtotime($event['start_date'])) . ')' : '') : 'Event';

ob_start(); ?>
<div class="feedback-analysis-page">
    <form method="get" class="feedback-analysis-filters no-print">
        <label>Event<select name="event_id"><?php foreach ($events as $option): ?><option value="<?php echo (int) $option['event_id']; ?>" <?php echo $eventId === (int) $option['event_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['event_title']); ?></option><?php endforeach; ?></select></label>
        <label>Feedback type<select name="feedback_type_id"><?php foreach ($types as $option): ?><option value="<?php echo (int) $option['feedback_type_id']; ?>" <?php echo $typeId === (int) $option['feedback_type_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['type_name']); ?></option><?php endforeach; ?></select></label>
        <label>Analyse by<select name="category"><?php foreach ($categories as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $category === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
        <button type="submit">Generate Analysis</button><button type="button" class="button-secondary" onclick="window.print()">Print / Save PDF</button>
    </form>

    <article class="feedback-analysis-report">
        <header><span>Feedback analysis report</span><h2><?php echo htmlspecialchars($eventDisplay); ?></h2><p><?php echo htmlspecialchars((string) ($selectedType['type_name'] ?? '')); ?> Feedback</p></header>
        <div class="feedback-analysis-metrics">
            <div><span>Invited</span><strong><?php echo $invitationStats['invited']; ?></strong></div>
            <div><span>Links sent</span><strong><?php echo $invitationStats['sent']; ?></strong></div>
            <div><span>Submitted</span><strong><?php echo $invitationStats['submitted']; ?></strong></div>
            <div><span>Response rate</span><strong><?php echo number_format($responseRate, 1); ?>%</strong></div>
            <div class="primary"><span>Overall score</span><strong><?php echo number_format($overallPercentage, 1); ?>%</strong></div>
        </div>

        <section><h3>Item-wise analysis</h3><div class="event-report-table-wrap"><table><thead><tr><th>SN</th><th>Feedback item</th><th>Responses</th><th>Average</th><th>Percentage</th></tr></thead><tbody>
        <?php foreach ($itemStats as $index => $item): ?><tr><td><?php echo $index + 1; ?></td><td><?php echo htmlspecialchars($item['item_text']); ?></td><td><?php echo $item['response_type'] === 'rating' ? (int) $item['rating_count'] : (int) $item['text_count']; ?></td><td><?php echo $item['response_type'] === 'rating' && (int) $item['rating_count'] > 0 ? number_format((float) $item['average_rating'], 2) . ' / 5' : 'Written responses'; ?></td><td><?php if ($item['response_type'] === 'rating'): ?><div class="analysis-percentage"><span style="width:<?php echo min(100, (float) $item['percentage']); ?>%"></span></div><strong><?php echo number_format((float) $item['percentage'], 1); ?>%</strong><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$itemStats): ?><tr><td colspan="5">No feedback items are available.</td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="4">Total feedback score</th><th><?php echo number_format($overallPercentage, 1); ?>%</th></tr></tfoot></table></div></section>

        <section><h3><?php echo htmlspecialchars($categories[$category]); ?>-wise analysis</h3><div class="event-report-table-wrap"><table><thead><tr><th><?php echo htmlspecialchars($categories[$category]); ?></th><th>Respondents</th><th>Rated answers</th><th>Score</th></tr></thead><tbody>
        <?php foreach ($breakdown as $row): ?><tr><td><?php echo htmlspecialchars($row['category_value']); ?></td><td><?php echo (int) $row['respondents']; ?></td><td><?php echo (int) $row['rating_count']; ?></td><td><strong><?php echo number_format((float) $row['percentage'], 1); ?>%</strong></td></tr><?php endforeach; ?>
        <?php if (!$breakdown): ?><tr><td colspan="4">No submitted feedback is available for this breakdown.</td></tr><?php endif; ?></tbody></table></div></section>

        <section><div class="qualitative-heading"><div><h3>Qualitative feedback analysis</h3><p>Automated text analysis of <?php echo (int) $qualitativeAnalysis['response_count']; ?> submitted response(s), filtered for this event and feedback type.</p></div><span class="analysis-method">Theme matching + keyword frequency</span></div>
        <?php if ($qualitativeResponses): ?><div class="event-report-table-wrap"><table class="qualitative-summary-table"><thead><tr><th>Subject / theme</th><th>Inspiration / impact</th><th>Suggestions taken for this event &amp; feedback type</th></tr></thead><tbody><tr>
        <?php foreach (['subject_summary', 'impact_summary', 'suggestions'] as $field): $column = $qualitativeAnalysis['columns'][$field] ?? ['themes' => [], 'keywords' => []]; ?><td><ol class="qualitative-theme-list">
            <?php foreach ($column['themes'] as $theme): ?><li><div><strong><?php echo htmlspecialchars($theme['label']); ?></strong><span><?php echo (int) $theme['mentions']; ?> mention<?php echo (int) $theme['mentions'] === 1 ? '' : 's'; ?></span></div><?php if (!empty($theme['examples'][0])): ?><q><?php echo htmlspecialchars($theme['examples'][0]); ?></q><?php endif; ?></li><?php endforeach; ?>
            <?php if (!$column['themes']): ?><li class="no-theme">No recurring predefined theme detected.</li><?php endif; ?>
        </ol><?php if ($column['keywords']): ?><div class="keyword-cloud"><span>Frequent terms</span><?php foreach ($column['keywords'] as $keyword => $count): ?><b><?php echo htmlspecialchars($keyword); ?> <small><?php echo (int) $count; ?></small></b><?php endforeach; ?></div><?php endif; ?></td><?php endforeach; ?>
        </tr></tbody></table></div><details class="qualitative-source-responses no-print"><summary>Review source responses (<?php echo count($qualitativeResponses); ?>)</summary><div class="event-report-table-wrap"><table><thead><tr><th>Subject / theme</th><th>Inspiration / impact</th><th>Suggestions</th></tr></thead><tbody><?php foreach ($qualitativeResponses as $row): ?><tr><td><?php echo nl2br(htmlspecialchars((string) ($row['subject_summary'] ?? ''))); ?></td><td><?php echo nl2br(htmlspecialchars((string) ($row['impact_summary'] ?? ''))); ?></td><td><?php echo nl2br(htmlspecialchars((string) ($row['suggestions'] ?? ''))); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php else: ?><p class="empty-analysis">No qualitative responses have been submitted yet.</p><?php endif; ?></section>
    </article>
</div>
<?php $content = ob_get_clean();
renderAdminLayout('Feedback Analysis', $content, ['current_path' => 'feedback_analysis', 'page_heading' => 'Feedback Analysis', 'body_class' => 'feedback-analysis-admin']);
