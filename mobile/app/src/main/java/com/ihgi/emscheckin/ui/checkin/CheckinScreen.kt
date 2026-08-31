package com.ihgi.emscheckin.ui.checkin

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.viewmodel.compose.viewModel
import com.ihgi.emscheckin.data.dto.RegistrationDto
import com.ihgi.emscheckin.ui.components.RecentCheckinsList
import com.ihgi.emscheckin.ui.components.StatusBanner
import com.ihgi.emscheckin.util.Tts

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CheckinScreen(
    onSessionExpired: () -> Unit,
    viewModel: CheckinViewModel = viewModel(),
) {
    val uiState by viewModel.uiState.collectAsState()
    val context = LocalContext.current
    val tts = remember { Tts(context) }

    DisposableEffect(Unit) {
        onDispose { tts.shutdown() }
    }

    LaunchedEffect(uiState.announcement) {
        uiState.announcement?.let {
            tts.speak(it)
            viewModel.consumeAnnouncement()
        }
    }

    LaunchedEffect(uiState.sessionExpired) {
        if (uiState.sessionExpired) onSessionExpired()
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Event Check-in") }) }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            EventPicker(
                events = uiState.events,
                selectedEventId = uiState.selectedEventId,
                onSelect = viewModel::selectEvent,
            )

            StatusBanner(status = uiState.status)

            val tabs = CheckinTab.entries.toList()
            TabRow(selectedTabIndex = tabs.indexOf(uiState.activeTab)) {
                tabs.forEach { tab ->
                    Tab(
                        selected = uiState.activeTab == tab,
                        onClick = { viewModel.selectTab(tab) },
                        text = { Text(tab.label()) },
                    )
                }
            }

            when (uiState.activeTab) {
                CheckinTab.QR -> QrTab(viewModel)
                CheckinTab.MANUAL -> ManualTab(uiState, viewModel)
                CheckinTab.CODE -> CodeTab(uiState, viewModel)
            }

            Text("Recent check-ins", style = MaterialTheme.typography.titleMedium)
            RecentCheckinsList(entries = uiState.recent, modifier = Modifier.weight(1f, fill = false))
        }
    }
}

private fun CheckinTab.label(): String = when (this) {
    CheckinTab.QR -> "QR"
    CheckinTab.MANUAL -> "Manual"
    CheckinTab.CODE -> "Code"
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EventPicker(events: List<com.ihgi.emscheckin.data.dto.EventDto>, selectedEventId: Int, onSelect: (Int) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    val selected = events.firstOrNull { it.event_id == selectedEventId }

    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = selected?.event_title ?: "Select event",
            onValueChange = {},
            readOnly = true,
            label = { Text("Event") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .fillMaxWidth()
                .menuAnchor(androidx.compose.material3.MenuAnchorType.PrimaryNotEditable, true),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            events.forEach { event ->
                androidx.compose.material3.DropdownMenuItem(
                    text = { Text(event.event_title) },
                    onClick = {
                        expanded = false
                        onSelect(event.event_id)
                    },
                )
            }
        }
    }
}

@Composable
private fun QrTab(viewModel: CheckinViewModel) {
    val context = LocalContext.current
    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED,
        )
    }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        hasCameraPermission = granted
    }

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    if (hasCameraPermission) {
        QrScannerView(onScanned = viewModel::onQrScanned)
    } else {
        Card(modifier = Modifier.fillMaxWidth().padding(16.dp)) {
            Text(
                "Camera permission is required to scan QR codes.",
                modifier = Modifier.padding(16.dp),
            )
        }
    }
}

@Composable
private fun ManualTab(uiState: CheckinUiState, viewModel: CheckinViewModel) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedTextField(
            value = uiState.manualSearch,
            onValueChange = viewModel::onManualSearchChange,
            label = { Text("Search registrations") },
            modifier = Modifier.fillMaxWidth(),
        )

        val filtered = uiState.filteredRegistrations
        if (filtered.isEmpty()) {
            Text("No registrations match your search.", style = MaterialTheme.typography.bodyMedium)
        } else {
            LazyColumn(modifier = Modifier.weight(1f, fill = false)) {
                items(filtered, key = { it.registration_id }) { reg ->
                    ManualRegistrationRow(reg = reg, onCheckIn = { viewModel.checkInManually(reg.registration_id) }, busy = uiState.isBusy)
                }
            }
        }
    }
}

@Composable
private fun ManualRegistrationRow(reg: RegistrationDto, onCheckIn: () -> Unit, busy: Boolean) {
    Card(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(12.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(reg.name, style = MaterialTheme.typography.bodyLarge)
                Text(
                    listOfNotNull(reg.designation, reg.institution_name).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            if (reg.checked_in_at != null) {
                Text("Checked in", style = MaterialTheme.typography.labelLarge)
            } else {
                Button(onClick = onCheckIn, enabled = !busy) { Text("Check in") }
            }
        }
    }
}

@Composable
private fun CodeTab(uiState: CheckinUiState, viewModel: CheckinViewModel) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = uiState.codeInput,
                onValueChange = viewModel::onCodeInputChange,
                label = { Text("5-digit check-in code") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                modifier = Modifier.weight(1f),
            )
            Button(onClick = viewModel::lookupCode, enabled = uiState.codeInput.length == 5 && !uiState.isBusy) {
                Text("Find")
            }
        }

        uiState.codeCandidate?.let { candidate ->
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(candidate.name, style = MaterialTheme.typography.titleMedium)
                    candidate.designation?.let { Text(it) }
                    candidate.institution_name?.let { Text(it) }
                    candidate.mobile?.let { Text(it) }
                    Button(onClick = viewModel::confirmCodeCheckin, enabled = !uiState.isBusy, modifier = Modifier.fillMaxWidth()) {
                        Text("Check in candidate")
                    }
                }
            }
        }
    }
}
