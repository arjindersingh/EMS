<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/google_sheets_funcs.php';
require_once __DIR__ . '/events_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

requireAdminAuthentication();

$pdo = createDbConnection();

// Ensure menu items are set up for this page
ensureMissingMenuItemsExist($pdo);

$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

$action = $_POST['action'] ?? '';
$eventId = (int) ($_POST['event_id'] ?? $_GET['event_id'] ?? 0);
$fetchResult = null;
$sheetURLInput = null;
$sheetTabs = [];
$selectedSheetGid = null;

// Handle form submissions
if ($action === 'load_sheet_tabs') {
	$sheetURLInput = trim((string) ($_POST['sheet_url'] ?? ''));
	if ($eventId <= 0) {
		$adminError = 'Event is required.';
	} elseif ($sheetURLInput === '' || convertToCSVExportURL($sheetURLInput) === null) {
		$adminError = 'Enter a valid Google Sheets URL before loading worksheet tabs.';
	} else {
		$selectedSheetGid = getGoogleSheetGid($sheetURLInput);
		$sheetTabs = getGoogleSheetTabs($sheetURLInput);
		if ($sheetTabs) {
			$adminSuccess = 'Worksheet tabs loaded. Choose a tab and save the sheet URL.';
		} else {
			$adminError = 'Could not load worksheet tabs. Check that the Google Sheet is publicly viewable and try again.';
		}
	}
} elseif ($action === 'save_sheet_url') {
	$sheetURL = trim($_POST['sheet_url'] ?? '');
	$sheetGidValue = $_POST['sheet_gid'] ?? (string) getGoogleSheetGid($sheetURL);
	
	if (!$eventId) {
		$adminError = 'Event is required.';
	} elseif (!$sheetURL) {
		$adminError = 'Google Sheet URL is required.';
	} elseif (!is_string($sheetGidValue) || !ctype_digit($sheetGidValue)) {
		$adminError = 'Choose a valid worksheet tab.';
	} else {
		$sheetURL = setGoogleSheetGid($sheetURL, (int) $sheetGidValue);
		if (saveGoogleSheetURL($pdo, $sheetURL, $eventId)) {
			$sheetURLInput = $sheetURL;
			$adminSuccess = 'Google Sheet URL saved successfully!';
		} else {
			$adminError = 'Failed to save Google Sheet URL.';
		}
	}
} elseif ($action === 'save_field_mapping') {
	$registrationFields = getRegistrationTableFields($pdo);
	$fieldMapping = [];
	$defaultValues = [];
	$rowFields = [];
	$submittedFields = $_POST['registration_field'] ?? [];
	$submittedMappings = $_POST['mapping'] ?? [];
	$submittedDefaults = $_POST['default'] ?? [];
	$validFields = array_flip($registrationFields);
	$mappingError = '';

	if (
		$eventId <= 0
		|| !is_array($submittedFields)
		|| !is_array($submittedMappings)
		|| !is_array($submittedDefaults)
		|| count($submittedFields) !== count($registrationFields)
	) {
		$mappingError = 'Please select one registration field for each row.';
	} else {
		foreach ($submittedFields as $index => $field) {
			$field = is_string($field) ? $field : '';
			if (!isset($validFields[$field]) || isset($fieldMapping[$field])) {
				$mappingError = 'Each registration field must be selected exactly once.';
				break;
			}

			$sheetField = $submittedMappings[$index] ?? '';
			$defaultValue = $submittedDefaults[$index] ?? '';
			$fieldMapping[$field] = is_string($sheetField) ? trim($sheetField) : '';
			$defaultValues[$field] = is_string($defaultValue) ? $defaultValue : '';
			$rowFields[] = $field;
		}
	}

	if ($mappingError !== '') {
		$adminError = $mappingError;
	} elseif (saveFieldMapping($pdo, $eventId, $fieldMapping, $defaultValues, $rowFields)) {
		$adminSuccess = 'Field mapping saved successfully!';
	} else {
		$adminError = 'Failed to save field mapping.';
	}
} elseif ($action === 'save_fetch_methods') {
	$manual = isset($_POST['fetch_manual_enabled']) ? true : false;
	$automatic = isset($_POST['fetch_automatic_enabled']) ? true : false;
	$realtime = isset($_POST['fetch_realtime_enabled']) ? true : false;
	
	$automaticInterval = (int) ($_POST['fetch_automatic_interval'] ?? 60);
	
	saveFetchMethod($pdo, $eventId, 'manual', $manual);
	saveFetchMethod($pdo, $eventId, 'automatic', $automatic, $automaticInterval);
	saveFetchMethod($pdo, $eventId, 'realtime', $realtime);
	
	$adminSuccess = 'Fetch methods updated successfully!';
} elseif ($action === 'fetch_now') {
	$sheetURL = getGoogleSheetURL($pdo, $eventId);
	$csvURL = convertToCSVExportURL($sheetURL);
	
	if (!$csvURL) {
		$adminError = 'Invalid Google Sheet URL.';
	} else {
		$result = fetchAndProcessRegistrations($pdo, $eventId, $csvURL);
		$fetchResult = $result;
		
		$adminSuccess = "Fetch complete! Total: {$result['total']}, Success: {$result['success']}, Duplicates: {$result['duplicates']}, Failed: {$result['failed']}";
		
		if (!empty($result['errors'])) {
			$adminError = 'Errors: ' . implode('; ', $result['errors']);
		}
		
		// Update last fetch time
		saveFetchMethod($pdo, $eventId, 'manual', true);
	}
}

// Get events
$events = getAllEvents($pdo);

// Get current event data
$currentEvent = null;
$currentSheetURL = null;
$currentFieldMapping = [];
$currentFieldDefaults = [];
$currentMappingRows = [];
$currentFetchMethods = [];

if ($eventId) {
	$currentEvent = getEventById($pdo, $eventId);
	$currentSheetURL = getGoogleSheetURL($pdo, $eventId);
	$currentFieldMapping = getFieldMapping($pdo, $eventId);
	$currentFieldDefaults = getFieldDefaultValues($pdo, $eventId);
	$currentMappingRows = getFieldMappingRows($pdo, $eventId, getRegistrationTableFields($pdo));
	
	$currentFetchMethods = [
		'manual' => getFetchMethod($pdo, $eventId, 'manual') ?? ['enabled' => false],
		'automatic' => getFetchMethod($pdo, $eventId, 'automatic') ?? ['enabled' => false, 'interval_minutes' => 60],
		'realtime' => getFetchMethod($pdo, $eventId, 'realtime') ?? ['enabled' => false],
	];
}

// Get registration table fields
$registrationFields = getRegistrationTableFields($pdo);

// Fetch sheet headers for field mapping if a URL is set
$sheetHeaders = [];
$sheetURLInput = $sheetURLInput ?? $currentSheetURL;
$sheetTabs = $action === 'load_sheet_tabs'
	? $sheetTabs
	: ($currentSheetURL ? getGoogleSheetTabs($currentSheetURL) : []);
$selectedSheetGid = $selectedSheetGid ?? ($currentSheetURL ? getGoogleSheetGid($currentSheetURL) : 0);
if ($currentSheetURL) {
	$csvURL = convertToCSVExportURL($currentSheetURL);
	if ($csvURL) {
		$sheetData = fetchGoogleSheetData($csvURL);
		if ($sheetData) {
			$sheetHeaders = $sheetData['headers'];
		}
	}
}

$hasFieldMapping = false;
foreach ($currentFieldMapping as $sheetField) {
	if (is_string($sheetField) && trim($sheetField) !== '') {
		$hasFieldMapping = true;
		break;
	}
}
if (!$hasFieldMapping) {
	foreach ($currentFieldDefaults as $defaultValue) {
		if (is_string($defaultValue) && trim($defaultValue) !== '') {
			$hasFieldMapping = true;
			break;
		}
	}
}

ob_start();
?>
<style>
	.settings-section {
		background: #f9f9f9;
		border: 1px solid #ddd;
		border-radius: 6px;
		padding: 20px;
		margin-bottom: 30px;
	}

	.settings-section h3 {
		margin-top: 0;
		padding-bottom: 10px;
		border-bottom: 2px solid #007bff;
		margin-bottom: 20px;
	}

	.form-group {
		margin-bottom: 15px;
	}

	.form-group label {
		display: block;
		margin-bottom: 5px;
		font-weight: 500;
		color: #333;
	}

	.form-group input[type="text"],
	.form-group input[type="url"],
	.form-group input[type="number"],
	.form-group select,
	.form-group textarea {
		width: 100%;
		padding: 10px;
		border: 1px solid #ccc;
		border-radius: 4px;
		font-size: 14px;
	}

	.form-row {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 20px;
	}

	.form-full {
		grid-column: 1 / -1;
	}

	.btn {
		padding: 10px 20px;
		border: none;
		border-radius: 4px;
		cursor: pointer;
		font-size: 14px;
		font-weight: 500;
		display: inline-block;
	}

	.btn-primary {
		background: #007bff;
		color: white;
	}

	.btn-primary:hover {
		background: #0056b3;
	}

	.btn-success {
		background: #28a745;
		color: white;
	}

	.btn-success:hover {
		background: #218838;
	}

	.btn-danger {
		background: #dc3545;
		color: white;
	}

	.btn-secondary {
		background: #6c757d;
		color: white;
	}

	.alert {
		padding: 15px;
		border-radius: 4px;
		margin-bottom: 20px;
	}

	.alert.error {
		background: #f8d7da;
		color: #721c24;
		border: 1px solid #f5c6cb;
	}

	.alert.success {
		background: #d4edda;
		color: #155724;
		border: 1px solid #c3e6cb;
	}

	.mapping-table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 15px;
	}

	.mapping-table th {
		background: #f5f5f5;
		padding: 12px;
		text-align: left;
		font-weight: 600;
		border-bottom: 2px solid #ddd;
	}

	.mapping-table td {
		padding: 12px;
		border-bottom: 1px solid #eee;
	}

	.mapping-table select {
		width: 100%;
		padding: 8px;
		border: 1px solid #ccc;
		border-radius: 4px;
	}

	.mapping-table input[type="text"] {
		width: 100%;
		padding: 8px;
		border: 1px solid #ccc;
		border-radius: 4px;
	}

	/* Enhanced Mapping Row Styles */
	.mapping-row {
		transition: background-color 0.2s ease;
	}

	.mapping-row:hover {
		background-color: #f9f9f9;
	}

	.btn-clear-mapping:hover {
		transform: scale(1.2);
		color: #a02830;
	}

	.field-select {
		transition: all 0.2s ease;
	}

	.field-select:focus {
		box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
		border-color: #007bff;
	}

	/* Quick Actions Bar */
	.quick-actions {
		display: flex;
		gap: 10px;
		flex-wrap: wrap;
		margin-top: 15px;
		padding: 15px;
		background: #f8f9fa;
		border-radius: 4px;
	}

	.btn-secondary {
		background: #6c757d;
		color: white;
	}

	.btn-secondary:hover {
		background: #5a6268;
	}

	/* Mapping Status Badge */
	#mappingStatus {
		font-weight: 600;
		display: inline-block;
		padding: 8px 12px;
		background: #e7f3ff;
		border-radius: 4px;
	}

	.fetch-method {
		background: white;
		border: 1px solid #ddd;
		border-radius: 4px;
		padding: 15px;
		margin-bottom: 15px;
	}

	.fetch-method-header {
		display: flex;
		align-items: center;
		gap: 10px;
		margin-bottom: 10px;
	}

	.fetch-method-header input[type="checkbox"] {
		cursor: pointer;
		width: 18px;
		height: 18px;
	}

	.fetch-method-header label {
		font-weight: 600;
		cursor: pointer;
		margin: 0;
	}

	.fetch-method-settings {
		display: none;
		padding-top: 10px;
		border-top: 1px solid #eee;
	}

	.fetch-method-settings.show {
		display: block;
	}

	.no-event {
		text-align: center;
		padding: 40px 20px;
		color: #666;
	}

	.step-indicator {
		display: flex;
		gap: 20px;
		margin-bottom: 30px;
		flex-wrap: wrap;
	}

	.step {
		display: flex;
		align-items: center;
		gap: 10px;
		font-weight: 500;
		color: #666;
	}

	.step-number {
		background: #e9ecef;
		color: #495057;
		width: 30px;
		height: 30px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-weight: 600;
	}

	.step.active .step-number {
		background: #007bff;
		color: white;
	}
</style>

<h1>Fetch Registration Data</h1>

<?php if ($adminError): ?>
	<div class="alert error"><?php echo htmlspecialchars($adminError); ?></div>
<?php endif; ?>

<?php if ($adminSuccess): ?>
	<div class="alert success"><?php echo htmlspecialchars($adminSuccess); ?></div>
<?php endif; ?>

<?php if (is_array($fetchResult)): ?>
	<div class="settings-section">
		<h3>Fetch Results</h3>
		<p>
			<strong>Total processed:</strong> <?php echo (int) ($fetchResult['total'] ?? 0); ?> |
			<strong>Success:</strong> <?php echo (int) ($fetchResult['success'] ?? 0); ?> |
			<strong>Duplicates:</strong> <?php echo (int) ($fetchResult['duplicates'] ?? 0); ?> |
			<strong>Failed:</strong> <?php echo (int) ($fetchResult['failed'] ?? 0); ?>
		</p>
		<?php if (!empty($fetchResult['rows'])): ?>
			<div style="overflow-x: auto;">
				<table class="mapping-table">
					<thead>
						<tr>
							<th>Event</th>
							<th>Reg_id</th>
							<th>Name</th>
							<th>Status</th>
							<th>Detail</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($fetchResult['rows'] as $rowResult): ?>
							<?php
								$status = (string) ($rowResult['status'] ?? 'failed');
								$statusColor = match ($status) {
									'success' => '#155724',
									'duplicate' => '#856404',
									default => '#721c24',
								};
							?>
							<tr>
								<td><?php echo htmlspecialchars((string) ($rowResult['event'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($rowResult['registration_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars((string) ($rowResult['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
								<td style="color: <?php echo $statusColor; ?>; font-weight: 600;">
									<?php echo htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8'); ?>
								</td>
								<td><?php echo htmlspecialchars((string) ($rowResult['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<!-- Event Selection -->
<div class="settings-section">
	<h3>📌 Select Event</h3>
	
	<?php if (empty($events)): ?>
		<p style="color: #666;">No events found. <a href="<?php echo buildUrl('admin/create_event.php'); ?>">Create an event first</a>.</p>
	<?php else: ?>
		<div class="form-group">
			<label for="event_id">Event *</label>
			<select id="event_id" onchange="window.location.href='<?php echo buildUrl('admin/fetch_registration.php?event_id='); ?>' + this.value">
				<option value="">-- Select Event --</option>
				<?php foreach ($events as $event): ?>
					<option value="<?php echo $event['event_id']; ?>"
							<?php echo $eventId === (int) $event['event_id'] ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($event['event_title']); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
	<?php endif; ?>
</div>

<?php if ($eventId && $currentEvent): ?>

	<div class="step-indicator">
		<div class="step <?php echo $currentSheetURL ? 'active' : ''; ?>">
			<div class="step-number">1</div>
			<span>Configure Google Sheet</span>
		</div>
		<div class="step <?php echo !empty($registrationFields) ? 'active' : ''; ?>">
			<div class="step-number">2</div>
			<span>Map Fields</span>
		</div>
		<div class="step <?php echo !empty($currentFetchMethods) ? 'active' : ''; ?>">
			<div class="step-number">3</div>
			<span>Set Fetch Methods</span>
		</div>
		<div class="step">
			<div class="step-number">4</div>
			<span>Fetch Data</span>
		</div>
	</div>

	<!-- Step 1: Google Sheet Configuration -->
	<div class="settings-section">
		<h3>🔗 Step 1: Google Sheet Configuration</h3>

		<form method="post">
			<input type="hidden" name="event_id" value="<?php echo $eventId; ?>">

			<div class="form-group">
				<label for="sheet_url">Google Sheet URL</label>
				<input type="url" id="sheet_url" name="sheet_url" 
					   placeholder="https://docs.google.com/spreadsheets/d/YOUR_SHEET_ID/edit"
					   value="<?php echo htmlspecialchars($sheetURLInput ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
				<small style="color: #666; display: block; margin-top: 5px;">
					Paste the Google Sheets link. Make sure the sheet is publicly accessible or shared.
				</small>
			</div>

			<div class="form-group">
				<label for="sheet_gid">Worksheet tab</label>
				<?php if ($sheetTabs): ?>
					<select id="sheet_gid" name="sheet_gid">
						<?php
							$selectedTabExists = false;
							foreach ($sheetTabs as $sheetTab) {
								if ((int) $sheetTab['gid'] === $selectedSheetGid) {
									$selectedTabExists = true;
									break;
								}
							}
						?>
						<?php if (!$selectedTabExists): ?>
							<option value="<?php echo $selectedSheetGid; ?>" selected>
								Current worksheet (GID <?php echo $selectedSheetGid; ?>)
							</option>
						<?php endif; ?>
						<?php foreach ($sheetTabs as $sheetTab): ?>
							<option value="<?php echo (int) $sheetTab['gid']; ?>"
								<?php echo (int) $sheetTab['gid'] === $selectedSheetGid ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars((string) $sheetTab['title'], ENT_QUOTES, 'UTF-8'); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<small style="color: #666; display: block; margin-top: 5px;">
						Choose a tab, then save to use it for the import.
					</small>
				<?php else: ?>
					<select id="sheet_gid" name="sheet_gid">
						<option value="<?php echo $selectedSheetGid; ?>">Current worksheet (GID <?php echo $selectedSheetGid; ?>)</option>
					</select>
					<small style="color: #666; display: block; margin-top: 5px;">
						Worksheet names are not loaded yet. Click “Load worksheet tabs” to fetch the available sheets from this URL.
					</small>
				<?php endif; ?>
			</div>

			<button type="submit" name="action" value="load_sheet_tabs" class="btn btn-secondary">🔄 Load worksheet tabs</button>
			<button type="submit" name="action" value="save_sheet_url" class="btn btn-primary">💾 Save Sheet URL</button>
		</form>

		<?php if ($currentSheetURL): ?>
			<div style="margin-top: 15px; padding: 10px; background: #d4edda; border-radius: 4px; color: #155724;">
				✓ Sheet URL configured successfully
			</div>
		<?php endif; ?>
	</div>

	<!-- Step 2: Field Mapping -->
	<?php if ($registrationFields): ?>
		<div class="settings-section">
			<h3>🗂️ Step 2: Map Fields</h3>

			<p style="margin-bottom: 15px; color: #666;">
				Select a registration field for each row, then choose its matching Google Sheet column or enter a default value.
			</p>
			<?php if (empty($sheetHeaders)): ?>
				<p class="alert error">
					No Google Sheet columns are available. Check the saved sheet URL and make sure the sheet is accessible; you can still enter default values below.
				</p>
			<?php endif; ?>

			<form method="post" id="fieldMappingForm">
				<input type="hidden" name="action" value="save_field_mapping">
				<input type="hidden" name="event_id" value="<?php echo $eventId; ?>">

				<!-- Field Mapping Table with Enhanced UI -->
				<div style="overflow-x: auto;">
					<table class="mapping-table">
						<thead>
							<tr>
								<th style="width: 25%;">Registration Table Field</th>
								<th style="width: 35%;">Google Sheet Column</th>
								<th style="width: 30%;">Default Value</th>
								<th style="width: 10%; text-align: center;">Action</th>
							</tr>
						</thead>
						<tbody id="mappingTableBody">
							<?php foreach ($currentMappingRows as $rowIndex => $field): 
								$mappedColumn = $currentFieldMapping[$field] ?? '';
								$defaultValue = $currentFieldDefaults[$field] ?? '';
								$hasSavedMapping = array_key_exists($field, $currentFieldMapping);
								$isRequired = in_array($field, ['email', 'phone', 'name']);
								$isAutoMatched = false;
								
								// Auto-match logic: if sheet has similar column name
								if (!$hasSavedMapping) {
									foreach ($sheetHeaders as $header) {
										if (strtolower(str_replace('_', ' ', $field)) === strtolower(str_replace('_', ' ', $header))) {
											$mappedColumn = $header;
											$isAutoMatched = true;
											break;
										}
									}
								}
							?>
								<tr class="mapping-row" data-field="<?php echo htmlspecialchars($field, ENT_QUOTES, 'UTF-8'); ?>">
									<td>
										<select name="registration_field[<?php echo (int) $rowIndex; ?>]" class="registration-field-select" aria-label="Registration table field for row <?php echo (int) $rowIndex + 1; ?>" required>
											<?php foreach ($registrationFields as $registrationField): ?>
												<option value="<?php echo htmlspecialchars($registrationField, ENT_QUOTES, 'UTF-8'); ?>"
													<?php echo $field === $registrationField ? 'selected' : ''; ?>>
													<?php echo htmlspecialchars($registrationField, ENT_QUOTES, 'UTF-8'); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<small class="required-field" style="color: #dc3545; <?php echo $isRequired ? '' : 'display: none;'; ?>">Required</small>
									</td>
									<td>
										<select name="mapping[<?php echo (int) $rowIndex; ?>]" class="field-select" onchange="updateFieldStatus(this)">
											<option value="">-- Not Mapped --</option>
											<?php foreach ($sheetHeaders as $header): ?>
												<option value="<?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?>"
														<?php echo $mappedColumn === $header ? 'selected' : ''; ?>>
													<?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?>
													<?php if ($isAutoMatched && $mappedColumn === $header): ?>
														(auto-matched)
													<?php endif; ?>
												</option>
											<?php endforeach; ?>
										</select>
										<?php if ($mappedColumn): ?>
											<small class="mapping-confirmation" style="color: #28a745; display: block; margin-top: 4px;">
												✓ Mapped to: <?php echo htmlspecialchars($mappedColumn); ?>
											</small>
										<?php endif; ?>
									</td>
									<td>
										<input type="text" placeholder="e.g., 'Not Specified'" 
											   name="default[<?php echo (int) $rowIndex; ?>]"
											   class="default-value-input"
											   value="<?php echo htmlspecialchars((string) $defaultValue, ENT_QUOTES, 'UTF-8'); ?>"
											   style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
									</td>
									<td style="text-align: center;">
										<button type="button" class="btn-clear-mapping" onclick="clearMapping(this)" 
												style="background: none; border: none; color: #dc3545; cursor: pointer; font-size: 16px;" 
												title="Clear this mapping">✕</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Quick Actions -->
				<div style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 4px; display: flex; gap: 10px; flex-wrap: wrap;">
					<button type="button" class="btn btn-secondary" onclick="autoMatchFields()">
						🔗 Auto-Match Fields
					</button>
					<button type="button" class="btn btn-secondary" onclick="clearAllMappings()">
						🗑️ Clear All
					</button>
					<button type="button" class="btn btn-secondary" onclick="showSampleMapping()">
						📋 Show Sample Mapping
					</button>
					<button type="submit" class="btn btn-primary" style="margin-left: auto;">
						💾 Save Field Mapping
					</button>
				</div>

				<!-- Mapping Status -->
				<div style="margin-top: 15px; padding: 12px; background: #e7f3ff; border-left: 4px solid #2196F3; border-radius: 4px;">
					<strong>Mapping Status:</strong> <span id="mappingStatus">Loading...</span>
				</div>
			</form>

		</div>
	<?php else: ?>
		<div class="settings-section">
			<h3>🗂️ Step 2: Map Fields</h3>
			<p class="alert error">The registration table was not found. Make sure the <code>event_registrations</code> table exists, then reload this page.</p>
		</div>
	<?php endif; ?>

	<!-- Step 3: Fetch Methods Configuration -->
	<div class="settings-section">
		<h3>⚙️ Step 3: Configure Fetch Methods</h3>

		<form method="post">
			<input type="hidden" name="action" value="save_fetch_methods">
			<input type="hidden" name="event_id" value="<?php echo $eventId; ?>">

			<!-- Manual Fetch -->
			<div class="fetch-method">
				<div class="fetch-method-header">
					<input type="checkbox" id="fetch_manual_enabled" name="fetch_manual_enabled"
						   <?php echo $currentFetchMethods['manual']['enabled'] ? 'checked' : ''; ?>>
					<label for="fetch_manual_enabled">Manual Fetch</label>
				</div>
				<div class="fetch-method-settings">
					<p style="color: #666; font-size: 13px;">
						Click a button to manually fetch data from the Google Sheet.
					</p>
				</div>
			</div>

			<!-- Automatic Fetch -->
			<div class="fetch-method">
				<div class="fetch-method-header">
					<input type="checkbox" id="fetch_automatic_enabled" name="fetch_automatic_enabled"
						   <?php echo $currentFetchMethods['automatic']['enabled'] ? 'checked' : ''; ?>>
					<label for="fetch_automatic_enabled">Automatic Fetch</label>
				</div>
				<div class="fetch-method-settings <?php echo $currentFetchMethods['automatic']['enabled'] ? 'show' : ''; ?>">
					<div class="form-group">
						<label for="fetch_automatic_interval">Fetch every (minutes)</label>
						<input type="number" id="fetch_automatic_interval" name="fetch_automatic_interval" 
							   min="5" value="<?php echo $currentFetchMethods['automatic']['interval_minutes'] ?? 60; ?>">
					</div>
					<p style="color: #666; font-size: 13px;">
						Data will be fetched automatically at specified intervals.
					</p>
				</div>
			</div>

			<!-- Real-time Fetch -->
			<div class="fetch-method">
				<div class="fetch-method-header">
					<input type="checkbox" id="fetch_realtime_enabled" name="fetch_realtime_enabled"
						   <?php echo $currentFetchMethods['realtime']['enabled'] ? 'checked' : ''; ?>>
					<label for="fetch_realtime_enabled">Real-time Fetch (Webhook)</label>
				</div>
				<div class="fetch-method-settings">
					<p style="color: #666; font-size: 13px;">
						Data will be fetched immediately when new entries are submitted to the Google Form.
					</p>
					<?php if ($currentFetchMethods['realtime']['enabled']): ?>
						<div style="background: #e7f3ff; padding: 10px; border-radius: 4px; margin-top: 10px;">
							<strong>Webhook URL:</strong><br>
							<code style="word-break: break-all;">
								<?php echo buildUrl('admin/google_sheets_webhook.php?event_id=' . $eventId); ?>
							</code>
							<br>
							<small style="color: #666;">Add this to your Google Form's notification settings</small>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<button type="submit" class="btn btn-primary" style="margin-top: 15px;">✅ Save Fetch Methods</button>
		</form>
	</div>

	<!-- Step 4: Manual Fetch -->
	<div class="settings-section">
		<h3>📥 Step 4: Fetch Data Now</h3>

		<?php if (!$currentSheetURL): ?>
			<p style="color: #dc3545;">⚠️ Please configure Google Sheet URL first.</p>
		<?php elseif (!$hasFieldMapping): ?>
			<p style="color: #dc3545;">⚠️ Please map at least one field first.</p>
		<?php else: ?>
			<form method="post">
				<input type="hidden" name="action" value="fetch_now">
				<input type="hidden" name="event_id" value="<?php echo $eventId; ?>">

				<p style="margin-bottom: 15px; color: #666;">
					Click the button below to manually fetch and import registration data from the Google Sheet.
					Only new entries (not duplicates) will be imported.
				</p>

				<button type="submit" class="btn btn-success" onclick="return confirm('Fetch data from Google Sheet now?');">
					🚀 Fetch Data Now
				</button>
			</form>
		<?php endif; ?>
	</div>

<?php else: ?>
	<div class="no-event">
		<p style="font-size: 16px;">👆 Please select an event to get started</p>
	</div>
<?php endif; ?>

<script>
	// Toggle automatic interval input
	document.getElementById('fetch_automatic_enabled').addEventListener('change', function() {
		const settingsDiv = document.querySelector('.fetch-method-settings.show');
		if (settingsDiv) {
			settingsDiv.classList.remove('show');
		}
		if (this.checked) {
			this.closest('.fetch-method').querySelector('.fetch-method-settings').classList.add('show');
		}
	});

	// Initialize mapping status on page load
	document.addEventListener('DOMContentLoaded', function() {
		updateMappingStatus();
		
		document.querySelectorAll('.field-select, .default-value-input').forEach(control => {
			control.addEventListener('change', updateMappingStatus);
			control.addEventListener('input', updateMappingStatus);
		});
		const registrationFieldSelects = Array.from(document.querySelectorAll('.registration-field-select'));
		registrationFieldSelects.forEach(select => {
			select.dataset.previousValue = select.value;
			select.addEventListener('change', function() {
				const previousField = this.dataset.previousValue;
				const duplicateSelect = registrationFieldSelects.find(other => other !== this && other.value === this.value);
				if (duplicateSelect) {
					duplicateSelect.value = previousField;
					duplicateSelect.dataset.previousValue = previousField;
					updateRequiredFieldLabel(duplicateSelect);
				}
				this.dataset.previousValue = this.value;
				this.closest('.mapping-row').dataset.field = this.value;
				updateRequiredFieldLabel(this);
				updateMappingStatus();
			});
		});
	});

	function updateRequiredFieldLabel(select) {
		const requiredLabel = select.closest('.mapping-row').querySelector('.required-field');
		requiredLabel.style.display = ['email', 'phone', 'name'].includes(select.value) ? '' : 'none';
	}

	// Update mapping status display
	function updateMappingStatus() {
		const rows = Array.from(document.querySelectorAll('.mapping-row'));
		const mappedFields = rows.length;
		const selectedFields = rows.filter(row =>
			row.querySelector('.field-select').value !== '' ||
			row.querySelector('.default-value-input').value.trim() !== ''
		).length;
		const statusDiv = document.getElementById('mappingStatus');
		
		if (statusDiv) {
			statusDiv.innerHTML = `<strong>${selectedFields} of ${mappedFields}</strong> fields mapped from a sheet column or default value`;
			
			if (selectedFields === 0) {
				statusDiv.innerHTML += ' <span style="color: #ff9800;">⚠️ No fields mapped</span>';
			} else if (selectedFields < 3) {
				statusDiv.innerHTML += ' <span style="color: #ff9800;">⚠️ Consider mapping more fields</span>';
			} else {
				statusDiv.innerHTML += ' <span style="color: #28a745;">✓ Good to go!</span>';
			}
		}
	}

	// Auto-match fields based on similarity
	function autoMatchFields() {
		const selects = document.querySelectorAll('.field-select');
		let matched = 0;
		
		selects.forEach(select => {
			if (select.value === '') { // Only auto-match unmapped fields
				const fieldName = select.closest('tr').querySelector('.registration-field-select').value.toLowerCase().replace(/_/g, ' ');
				const options = select.querySelectorAll('option');
				
				// Try to find an exact or similar match
				for (let option of options) {
					if (option.value === '') {
						continue;
					}
					const sheetName = option.value.toLowerCase().replace(/_/g, ' ');
					const dbName = fieldName;
					
					// Check for exact or partial matches
					if (sheetName === dbName || 
						sheetName.includes(dbName) || 
						dbName.includes(sheetName.split(' ')[0])) {
						select.value = option.value;
						matched++;
						updateMappingStatus();
						break;
					}
				}
			}
		});
		
		alert(`✓ Auto-matched ${matched} fields`);
		updateMappingStatus();
	}

	// Clear specific field mapping
	function clearMapping(button) {
		const row = button.closest('tr');
		const select = row.querySelector('.field-select');
		const input = row.querySelector('.default-value-input');
		
		select.value = '';
		input.value = '';
		updateMappingStatus();
	}

	// Clear all field mappings
	function clearAllMappings() {
		if (!confirm('Are you sure? This will clear all field mappings.')) {
			return;
		}
		
		document.querySelectorAll('.field-select').forEach(select => {
			select.value = '';
		});
		
		document.querySelectorAll('.default-value-input').forEach(input => {
			input.value = '';
		});
		
		updateMappingStatus();
	}

	// Show sample mapping (common example)
	function showSampleMapping() {
		const samples = {
			'name': 'Full Name of the Child',
			'email': 'Email Address',
			'phone': 'Mobile number of Parent',
			'gender': 'Gender of the Child',
			'course': 'Full Name of the Child',
		};
		
		let message = '📋 Sample Mapping for a typical School Event:\n\n';
		for (const [dbField, sheetField] of Object.entries(samples)) {
			message += `${dbField} → ${sheetField}\n`;
		}
		message += '\nAdjust these based on your actual Google Sheet columns.';
		
		alert(message);
	}

	// Update field status when selection changes
	function updateFieldStatus(select) {
		const row = select.closest('tr');
		const smallTag = select.parentNode.querySelector('.mapping-confirmation');
		
		if (select.value) {
			// Remove existing small tag if present
			if (smallTag) {
				smallTag.remove();
			}
			
			// Add new confirmation
			const newSmall = document.createElement('small');
			newSmall.className = 'mapping-confirmation';
			newSmall.style.color = '#28a745';
			newSmall.style.display = 'block';
			newSmall.style.marginTop = '4px';
			newSmall.innerHTML = '✓ Mapped to: ' + htmlEscape(select.value);
			select.parentNode.appendChild(newSmall);
		} else {
			if (smallTag) {
				smallTag.remove();
			}
		}
		
		updateMappingStatus();
	}

	// Escape HTML special characters
	function htmlEscape(text) {
		const map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return text.replace(/[&<>"']/g, m => map[m]);
	}

</script>

<?php
$content = ob_get_clean();

renderAdminLayout('Fetch Registration', $content, [
	'current_path' => 'fetch_registration',
	'page_heading' => 'Fetch Registration Data from Google Sheets',
	'show_sidebar' => true,
]);
