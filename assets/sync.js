(function () {
	const config = window.synchySyncConfig;

	if (!config) {
		return;
	}

	const form = document.querySelector("[data-synchy-sync-form]");
	const saveButton = document.querySelector("[data-synchy-save-sync]");
	const testButton = document.querySelector("[data-synchy-test-sync]");
	const previewButton = document.querySelector("[data-synchy-preview-sync]");
	const runButton = document.querySelector("[data-synchy-run-sync]");
	const manualBaselineButton = document.querySelector("[data-synchy-mark-baseline]");
	const destinationUrlInput = document.querySelector("[data-synchy-sync-url]");
	const usernameInput = document.querySelector("[data-synchy-sync-username]");
	const passwordInput = document.querySelector("[data-synchy-sync-password]");
	const verifySslInput = document.querySelector("[data-synchy-sync-verify-ssl]");
	const inlineConnectionStatus = document.querySelector("[data-synchy-sync-inline-status]");
	const progress = document.querySelector("[data-synchy-sync-progress]");
	const progressBar = document.querySelector("[data-synchy-sync-progress-bar]");
	const progressPhase = document.querySelector("[data-synchy-sync-progress-phase]");
	const progressPercent = document.querySelector("[data-synchy-sync-progress-percent]");
	const progressMessage = document.querySelector("[data-synchy-sync-progress-message]");
	const progressDetail = document.querySelector("[data-synchy-sync-progress-detail]");
	const stages = document.querySelector("[data-synchy-sync-stages]");
	const connectionPanel = document.querySelector("[data-synchy-sync-connection-result]");
	const connectionBadge = document.querySelector("[data-synchy-sync-connection-badge]");
	const connectionMessage = document.querySelector("[data-synchy-sync-connection-message]");
	const connectionMeta = document.querySelector("[data-synchy-sync-connection-meta]");
	const previewBadge = document.querySelector("[data-synchy-sync-preview-badge]");
	const previewMessage = document.querySelector("[data-synchy-sync-preview-message]");
	const previewTreeContainer = document.querySelector("[data-synchy-sync-preview-tree]");
	const statusBadge = document.querySelector("[data-synchy-sync-status-badge]");
	const statusSummary = document.querySelector("[data-synchy-sync-status-summary]");
	const targetNote = document.querySelector("[data-synchy-sync-target-note]");
	const scopeInputs = Array.from(document.querySelectorAll("[data-synchy-sync-scope]"));

	if (
		!form ||
		!testButton ||
		!previewButton ||
		!runButton ||
		!manualBaselineButton ||
		!saveButton ||
		!destinationUrlInput ||
		!usernameInput ||
		!passwordInput ||
		!previewBadge ||
		!previewMessage ||
		!statusBadge ||
		!statusSummary
	) {
		return;
	}

	let latestPreview = null;
	let busy = false;
	let pendingBaselineScopeIds = new Set((config.scopeStatus?.pendingBaselineScopeIds || []).map(String));
	let changedScopeIds = new Set();
	let currentJob = config.currentJob || null;
	let currentConnectionState = config.connectionState || null;
	let connectionVerified = Boolean(currentConnectionState && currentConnectionState.status === "connected");
	const initialConnectionState = {
		destinationUrl: destinationUrlInput.value.trim(),
		username: usernameInput.value.trim(),
		verifySsl: verifySslInput?.checked ? "1" : "0",
	};

	const escapeHtml = (value) =>
		String(value)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");

	const formatDateTime = (value) => {
		if (value === null || value === undefined || value === "") {
			return config.strings.never || "Never";
		}

		let date = null;

		if (typeof value === "number") {
			date = new Date(value * 1000);
		} else if (/^\d+$/.test(String(value))) {
			date = new Date(Number(value) * 1000);
		} else {
			date = new Date(String(value));
		}

		return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
	};

	const formatDuration = (seconds) => {
		const numeric = Number(seconds || 0);

		if (!Number.isFinite(numeric) || numeric <= 0) {
			return config.strings.na || "N/A";
		}

		const rounded = Math.max(1, Math.round(numeric));
		const hours = Math.floor(rounded / 3600);
		const minutes = Math.floor((rounded % 3600) / 60);
		const secs = rounded % 60;

		if (hours > 0) {
			return `${hours}h ${minutes}m`;
		}

		if (minutes > 0) {
			return `${minutes}m ${secs}s`;
		}

		return `${secs}s`;
	};

	const formatBytes = (bytes) => {
		const numeric = Number(bytes || 0);

		if (!Number.isFinite(numeric) || numeric <= 0) {
			return "0 B";
		}

		const units = ["B", "KB", "MB", "GB"];
		let value = numeric;
		let unitIndex = 0;

		while (value >= 1024 && unitIndex < units.length - 1) {
			value /= 1024;
			unitIndex += 1;
		}

		return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
	};

	const renderMeta = (container, items) => {
		if (!container) {
			return;
		}

		const filtered = items.filter((item) => item && item.value !== undefined && item.value !== null && item.value !== "");

		if (filtered.length === 0) {
			container.innerHTML = "";
			return;
		}

		container.innerHTML = filtered
			.map(
				(item) =>
					`<div><span class="synchy-export-meta__label">${escapeHtml(item.label)}</span><strong>${item.html ? item.value : escapeHtml(item.value)}</strong></div>`
			)
			.join("");
	};

	const renderStages = (job) => {
		if (!stages) {
			return;
		}

		const items = Array.isArray(job?.stages) ? job.stages : Array.isArray(config.defaultStages) ? config.defaultStages : [];

		stages.innerHTML = items
			.map(
				(stage) => `
					<div class="synchy-export-stage is-${escapeHtml(stage.state || "pending")}">
						<span class="synchy-export-stage__indicator" aria-hidden="true"></span>
						<div class="synchy-export-stage__content">
							<strong>${escapeHtml(stage.label || "")}</strong>
						</div>
					</div>
				`
			)
			.join("");
	};

	const renderProgress = (job) => {
		if (!progress) {
			renderStages(job);
			return;
		}

		if (!job || !job.status) {
			progress.classList.add("is-hidden");
			renderStages(null);
			return;
		}

		progress.classList.remove("is-hidden");

		if (progressBar) {
			progressBar.style.width = `${job.progress || 0}%`;
		}

		if (progressPhase) {
			progressPhase.textContent = job.phaseLabel || (config.strings.syncRunning || "Sync running");
		}

		if (progressPercent) {
			progressPercent.textContent = `${job.progress || 0}%`;
		}

		if (progressMessage) {
			progressMessage.textContent = job.message || "";
		}

		if (progressDetail) {
			progressDetail.textContent = `${config.strings.selectedChanges || "Selected changes"}: ${Number(job.filesCount || 0).toLocaleString()} files, ${Number(job.dbRows || 0).toLocaleString()} DB rows`;
		}

		renderStages(job);
	};

	const getSelectedScopeIds = () =>
		scopeInputs
			.filter((input) => String(input.value || "") === "1")
			.map((input) => String(input.dataset.scopeId || ""));

	const getSelectedScopeLabels = () =>
		getSelectedScopeIds()
			.map((scopeId) => document.querySelector(`[data-synchy-sync-scope-row][data-scope-id="${scopeId}"] strong`)?.textContent?.trim() || "")
			.filter(Boolean);

	const getHasSelection = () => getSelectedScopeIds().length > 0;

	const getPreviewSelectionInputs = () => Array.from(form.querySelectorAll("[data-synchy-preview-selection]"));

	const getHasSelectedPreviewItems = () => {
		const inputs = getPreviewSelectionInputs().filter((input) => input.type === "checkbox");

		if (inputs.length === 0) {
			return false;
		}

		return inputs.some((input) => input.checked);
	};

	const getHasPreviewChanges = () =>
		latestPreview !== null
		&& ((Number(latestPreview.filesCount || 0) > 0) || (Number(latestPreview.dbRows || 0) > 0));

	const getHasPendingBaselineSelection = () =>
		getSelectedScopeIds().some((scopeId) => pendingBaselineScopeIds.has(String(scopeId)));

	const getRunActionLabel = () =>
		getHasPendingBaselineSelection()
			? (config.strings.startBaseline || "Start Baseline")
			: (config.strings.pushChanges || "Push Changes");

	const updateTargetNote = () => {
		if (!targetNote) {
			return;
		}

		const destination = destinationUrlInput?.value?.trim() || "the destination URL above";
		targetNote.textContent = `Sync sends changes only to ${destination}.`;
	};

	const getSelectedScopeIdSet = () => new Set(getSelectedScopeIds().map(String));

	const updateScopeRows = () => {
		scopeInputs.forEach((input) => {
			const row = document.querySelector(`[data-synchy-sync-scope-row][data-scope-id="${String(input.dataset.scopeId || "")}"]`);
			const status = row?.querySelector("[data-synchy-sync-scope-status]");
			const scopeId = String(input.dataset.scopeId || "");
			const pending = pendingBaselineScopeIds.has(scopeId);
			const changed = changedScopeIds.has(scopeId);

			if (!row || !status) {
				return;
			}

			row.classList.toggle("is-pending", pending);
			row.classList.toggle("is-complete", !pending && !changed);
			row.classList.toggle("is-changed", !pending && changed);
			status.classList.remove("synchy-badge--muted", "synchy-badge--warning", "synchy-badge--connected");

			if (pending) {
				status.textContent = config.strings.needsBaseline || "Needs baseline";
				status.classList.add("synchy-badge--warning");
				return;
			}

			if (changed) {
				status.textContent = config.strings.pendingChanges || "Pending changes";
				status.classList.add("synchy-badge--warning");
				return;
			}

			status.textContent = latestPreview
				? (config.strings.noChangesInScope || "No changes")
				: (config.strings.readyForPreview || "Ready for preview");
			status.classList.add("synchy-badge--muted");
		});
	};

	const hasSavedPassword = () => passwordInput.dataset.hasSavedPassword === "1";

	const getConnectionDirty = () =>
		destinationUrlInput.value.trim() !== initialConnectionState.destinationUrl
		|| usernameInput.value.trim() !== initialConnectionState.username
		|| (verifySslInput?.checked ? "1" : "0") !== initialConnectionState.verifySsl
		|| passwordInput.value.trim() !== "";

	const hasConnectionCoreValues = () =>
		destinationUrlInput.value.trim() !== ""
		&& usernameInput.value.trim() !== ""
		&& (passwordInput.value.trim() !== "" || hasSavedPassword());

	const hasConfirmedConnection = () =>
		!getConnectionDirty()
		&& (connectionVerified || currentConnectionState?.status === "connected");

	const updateConnectionControls = () => {
		const dirty = getConnectionDirty();

		saveButton.disabled = busy || !dirty;
		testButton.disabled = busy || !hasConnectionCoreValues() || hasConfirmedConnection();

		if (!inlineConnectionStatus) {
			return;
		}

		inlineConnectionStatus.classList.remove("synchy-badge--muted", "synchy-badge--warning", "synchy-badge--connected");

		if (hasConfirmedConnection()) {
			inlineConnectionStatus.textContent = config.strings.connected || "Connected";
			inlineConnectionStatus.classList.add("synchy-badge--connected");
			return;
		}

		if (!dirty && currentConnectionState?.status === "error" && hasConnectionCoreValues()) {
			inlineConnectionStatus.textContent = config.strings.failed || "Failed";
			inlineConnectionStatus.classList.add("synchy-badge--warning");
			return;
		}

		if (dirty && hasConnectionCoreValues()) {
			inlineConnectionStatus.textContent = config.strings.needsRetest || "Needs retest";
			inlineConnectionStatus.classList.add("synchy-badge--warning");
			return;
		}

		inlineConnectionStatus.textContent = hasConnectionCoreValues()
			? (config.strings.notChecked || "Not checked")
			: (config.strings.incomplete || "Incomplete");
		inlineConnectionStatus.classList.add("synchy-badge--muted");
	};

	const updateActionButtons = () => {
		const hasSelection = getHasSelection();
		const runLabel = getRunActionLabel();

		previewButton.disabled = busy || !hasSelection;
		runButton.disabled = busy || !hasSelection || !getHasPreviewChanges() || !getHasSelectedPreviewItems();
		runButton.textContent = busy ? (config.strings.syncingAction || "Syncing...") : runLabel;

		if (manualBaselineButton) {
			const showManualBaseline = hasSelection && getHasPendingBaselineSelection();
			manualBaselineButton.style.display = showManualBaseline ? "" : "none";
			manualBaselineButton.disabled = busy || !showManualBaseline;
		}
	};

	const setBusy = (isBusy) => {
		busy = isBusy;
		updateConnectionControls();
		updateActionButtons();
	};

	const applyScopeStatus = (scopeStatus) => {
		if (!scopeStatus || !Array.isArray(scopeStatus.pendingBaselineScopeIds)) {
			return;
		}

		pendingBaselineScopeIds = new Set(scopeStatus.pendingBaselineScopeIds.map(String));
		updateScopeRows();
		updateActionButtons();
	};

	const renderConnectionResult = (payload, isError = false) => {
		if (!connectionPanel || !connectionBadge || !connectionMessage || !connectionMeta) {
			return;
		}

		connectionPanel.classList.remove("is-hidden");
		connectionBadge.textContent = isError ? (config.strings.connectionError || "Connection failed") : (config.strings.connectionReady || "Connection ready");
		connectionMessage.textContent = isError
			? payload.message || config.strings.unknownError || "Synchy hit an unexpected Sync error."
			: payload.message || "Destination site is ready for Sync.";

		if (isError) {
			renderMeta(connectionMeta, []);
			return;
		}

		renderMeta(connectionMeta, [
			{ label: config.strings.site || "Site", value: payload.name || "" },
			{ label: config.strings.destination || "Destination", value: payload.siteUrl || "" },
			{ label: config.strings.pluginVersion || "Plugin version", value: payload.pluginVersion || "" },
			{ label: config.strings.authenticatedAs || "Authenticated as", value: payload.authenticatedAs || "" },
		]);
	};

	const performConnectionTest = async () => {
		try {
			const data = await sendAjax("synchy_test_sync_connection");
			currentConnectionState = {
				status: "connected",
				message: config.strings.connectionReady || "Connection ready",
				remoteSite: data.remoteSite || {},
			};
			connectionVerified = true;
			renderConnectionResult(data.remoteSite || {}, false);
			updateConnectionControls();
			return { ok: true, remoteSite: data.remoteSite || {} };
		} catch (error) {
			currentConnectionState = {
				status: "error",
				message: error.message,
				remoteSite: {},
			};
			connectionVerified = false;
			renderConnectionResult({ message: error.message }, true);
			updateConnectionControls();
			return { ok: false, message: error.message };
		}
	};

	const renderPreviewTree = (preview) => {
		if (!previewTreeContainer) {
			return;
		}

		const tree = preview?.previewTree || null;
		const fileGroups = Array.isArray(tree?.fileGroups) ? tree.fileGroups : [];
		const databaseTables = Array.isArray(tree?.databaseTables) ? tree.databaseTables : [];
		const nextChangedScopeIds = new Set();

		fileGroups.forEach((group) => {
			if (group?.id) {
				nextChangedScopeIds.add(String(group.id));
			}
		});

		databaseTables.forEach((table) => {
			if (table?.scopeId) {
				nextChangedScopeIds.add(String(table.scopeId));
			}
		});

		changedScopeIds = nextChangedScopeIds;
		updateScopeRows();

		if (fileGroups.length === 0 && databaseTables.length === 0) {
			previewTreeContainer.innerHTML = "";
			previewTreeContainer.classList.add("is-hidden");
			updateActionButtons();
			return;
		}

		const existingFileSelection = new Set(
			Array.from(form.querySelectorAll('input[name="synchy_sync_selected_file_scopes[]"]:checked')).map((input) => String(input.value || ""))
		);
		const existingTableSelection = new Set(
			Array.from(form.querySelectorAll('input[name="synchy_sync_selected_db_tables[]"]:checked')).map((input) => String(input.value || ""))
		);
		const hasExistingSelection = existingFileSelection.size > 0 || existingTableSelection.size > 0;
		const isChecked = (value, set) => (!hasExistingSelection ? true : set.has(String(value)));
		const selectedScopeIds = getSelectedScopeIdSet();

		const fileGroupHtml = fileGroups
			.map((group) => {
				const paths = Array.isArray(group.paths) ? group.paths : [];
				const visiblePaths = paths.slice(0, 200);

				return `
					<div class="synchy-sync-tree__node">
						<label class="synchy-sync-tree__toggle">
							<input
								type="checkbox"
								name="synchy_sync_selected_file_scopes[]"
								value="${escapeHtml(group.id || "")}"
								data-synchy-preview-selection
								data-scope-id="${escapeHtml(group.id || "")}"
								${isChecked(group.id || "", existingFileSelection) ? "checked" : ""}
								${selectedScopeIds.has(String(group.id || "")) ? "" : "disabled"}
							/>
							<span>
								<strong>${escapeHtml(group.label || "")}</strong>
								<small>${escapeHtml(String(group.count || 0))} files • ${escapeHtml(formatBytes(group.bytes || 0))}</small>
							</span>
						</label>
						<details class="synchy-sync-tree__details">
							<summary>${escapeHtml(config.strings.changedFiles || "Changed files")}</summary>
							<ul class="synchy-sync-tree__list">
								${visiblePaths.map((path) => `<li>${escapeHtml(path)}</li>`).join("")}
								${paths.length > visiblePaths.length ? `<li>${escapeHtml(`... and ${paths.length - visiblePaths.length} more files in this section.`)}</li>` : ""}
							</ul>
						</details>
					</div>
				`;
			})
			.join("");

		const dbTableHtml = databaseTables
			.map((table) => {
				const rowIds = Array.isArray(table.rowIds) ? table.rowIds : [];

				return `
					<div class="synchy-sync-tree__node">
						<label class="synchy-sync-tree__toggle">
							<input
								type="checkbox"
								name="synchy_sync_selected_db_tables[]"
								value="${escapeHtml(table.table || "")}"
								data-synchy-preview-selection
								data-scope-id="${escapeHtml(table.scopeId || "")}"
								${isChecked(table.table || "", existingTableSelection) ? "checked" : ""}
								${selectedScopeIds.has(String(table.scopeId || "")) ? "" : "disabled"}
							/>
							<span>
								<strong>${escapeHtml(table.label || "")}</strong>
								<small>${escapeHtml(String(table.rowCount || 0))} rows${table.scopeLabel ? ` • ${escapeHtml(table.scopeLabel)}` : ""}</small>
							</span>
						</label>
						${rowIds.length > 0 ? `<p class="synchy-sync-tree__sample">${escapeHtml(config.strings.sampleRowIds || "Sample row IDs")}: ${escapeHtml(rowIds.join(", "))}</p>` : ""}
					</div>
				`;
			})
			.join("");

		previewTreeContainer.innerHTML = `
			<input type="hidden" name="synchy_sync_preview_selection_present" value="1" data-synchy-preview-selection-marker />
			${fileGroups.length > 0 ? `
				<div class="synchy-sync-tree__section">
					<h4>${escapeHtml(config.strings.files || "Files")}</h4>
					${fileGroupHtml}
				</div>
			` : ""}
			${databaseTables.length > 0 ? `
				<div class="synchy-sync-tree__section">
					<h4>${escapeHtml(config.strings.dbTables || "Database tables")}</h4>
					${dbTableHtml}
				</div>
			` : ""}
		`;
		previewTreeContainer.classList.remove("is-hidden");
		updateActionButtons();
	};

	const renderPreview = (preview) => {
		if (!preview) {
			previewBadge.textContent = "";
			previewMessage.textContent = config.strings.previewDefault || "Run Preview Changes to load the pending file sections and database tables.";
			renderPreviewTree(null);
			return;
		}

		const mode = String(preview.mode || "delta").toLowerCase() === "baseline"
			? (config.strings.baseline || "Baseline")
			: (config.strings.delta || "Delta");
		const filesCount = Number(preview.filesCount || 0);
		const dbRows = Number(preview.dbRows || 0);
		previewBadge.textContent = mode;

		if (filesCount === 0 && dbRows === 0) {
			previewMessage.textContent = "No pending changes detected since the last successful Sync.";
		} else {
			previewMessage.textContent = config.strings.previewSelectionHelp || "Review the pending file sections and database tables, then uncheck anything you do not want to send.";
		}

		renderPreviewTree(preview);
	};

	const getStatusBadge = (status) => {
		switch (String(status?.status || "")) {
			case "success":
				return config.strings.success || "Success";
			case "error":
				return config.strings.error || "Error";
			case "idle":
				return config.strings.noChanges || "No changes";
			default:
				return config.strings.awaitingBaseline || "Awaiting baseline";
		}
	};

	const getStatusMessage = (status) => {
		if (status && status.message) {
			return status.message;
		}

		return "No Sync has completed yet.";
	};

	const buildStatusSummary = (status) => {
		if (String(status?.status || "") === "error") {
			return getStatusMessage(status);
		}

		const lastSync = formatDateTime(status?.lastSyncTime || "");
		const destination = status?.destinationUrl || "";
		const files = Number(status?.filesSynced || 0).toLocaleString();
		const dbRows = Number(status?.dbRowsSynced || 0).toLocaleString();
		const mode = String(status?.mode || "").toLowerCase() === "baseline"
			? (config.strings.baseline || "Baseline")
			: String(status?.mode || "") === ""
				? (config.strings.delta || "Delta")
				: (config.strings.delta || "Delta");
		const duration = formatDuration(status?.durationSeconds || 0);

		return `Last Sync: ${lastSync} | ${destination || "Not set"} | ${files} files | ${dbRows} DB rows | ${mode} | ${duration}`;
	};

	const renderStatus = (status) => {
		statusBadge.textContent = getStatusBadge(status);
		statusSummary.textContent = buildStatusSummary(status);
	};

	const clearPreview = () => {
		latestPreview = null;
		changedScopeIds = new Set();
		renderPreview(null);
		updateScopeRows();
		updateActionButtons();
	};

	const collectFormData = (action) => {
		const formData = new FormData(form);
		formData.append("action", action);
		formData.append("nonce", config.nonce);
		return formData;
	};

	const sendAjax = async (action) => {
		const response = await fetch(config.ajaxUrl, {
			method: "POST",
			body: collectFormData(action),
			credentials: "same-origin",
		});

		const raw = await response.text();
		let payload = null;

		try {
			payload = raw ? JSON.parse(raw) : null;
		} catch (error) {
			payload = null;
		}

		if (!response.ok || !payload || payload.success !== true) {
			const message = payload?.data?.message || payload?.message || config.strings.unknownError || "Synchy hit an unexpected Sync error.";
			throw new Error(message);
		}

		return payload.data || {};
	};

	const pollSyncJob = async () => {
		if (!busy) {
			return;
		}

		try {
			const data = await sendAjax("synchy_get_sync_job_status");
			currentJob = data.job || null;
			renderProgress(currentJob);

			if (currentJob && currentJob.status === "running") {
				window.setTimeout(pollSyncJob, 250);
				return;
			}
		} catch (error) {
			if (busy) {
				window.setTimeout(pollSyncJob, 500);
			}
		}
	};

	const requireSelection = () => {
		if (getHasSelection()) {
			return true;
		}

		previewBadge.textContent = config.strings.previewError || "Preview failed";
		previewMessage.textContent = config.strings.selectAtLeastOneScope || "Select at least one file or database scope first.";
		return false;
	};

	const runTestConnection = async () => {
		setBusy(true);

		try {
			await performConnectionTest();
		} finally {
			setBusy(false);
		}
	};

	const runPreview = async () => {
		if (!requireSelection()) {
			updateActionButtons();
			return;
		}

		setBusy(true);

		try {
			if (!hasConfirmedConnection()) {
				const connectionCheck = await performConnectionTest();

				if (!connectionCheck.ok) {
					previewBadge.textContent = config.strings.previewError || "Preview failed";
					previewMessage.textContent = connectionCheck.message || config.strings.connectionError || "Connection failed";
					clearPreview();
					return;
				}
			}

			const data = await sendAjax("synchy_preview_sync_changes");
			latestPreview = data.preview || null;
			applyScopeStatus(data.scopeStatus || null);
			if (data.remoteSite) {
				currentConnectionState = {
					status: "connected",
					message: config.strings.connectionReady || "Connection ready",
					remoteSite: data.remoteSite,
				};
				connectionVerified = true;
				renderConnectionResult(data.remoteSite || {}, false);
			}
			renderPreview(latestPreview);
		} catch (error) {
			clearPreview();
			previewBadge.textContent = config.strings.previewError || "Preview failed";
			previewMessage.textContent = error.message;
		} finally {
			setBusy(false);
		}
	};

	const runManualBaseline = async () => {
		if (!requireSelection()) {
			updateActionButtons();
			return;
		}

		const destinationUrl = destinationUrlInput?.value?.trim() || "";
		const scopeLabels = getSelectedScopeLabels();
		const confirmMessage = [
			config.strings.confirmBaseline || "Mark the selected scopes as already baselined after a successful manual full restore to the destination site?",
			"",
			`Destination: ${destinationUrl || "Not set"}`,
			`Scopes: ${scopeLabels.join(", ") || "None"}`,
		].join("\n");

		if (!window.confirm(confirmMessage)) {
			return;
		}

		setBusy(true);
		statusBadge.textContent = config.strings.syncingAction || "Syncing...";
		statusSummary.textContent = "Saving the selected manual baseline state.";

		try {
			const data = await sendAjax("synchy_mark_sync_baseline_complete");
			renderStatus(data.status || {});
			applyScopeStatus(data.scopeStatus || null);
			clearPreview();
		} catch (error) {
			renderStatus({
				status: "error",
				message: error.message,
				at: new Date().toISOString(),
				lastSyncTime: "",
				filesSynced: 0,
				dbRowsSynced: 0,
				durationSeconds: 0,
				destinationUrl: destinationUrl || "",
				mode: "",
			});
		} finally {
			setBusy(false);
		}
	};

	const runSync = async () => {
		if (latestPreview === null) {
			return;
		}

		const destinationUrl = destinationUrlInput?.value?.trim() || "";
		const scopeLabels = getSelectedScopeLabels();
		const selectedFileSections = form.querySelectorAll('input[name="synchy_sync_selected_file_scopes[]"]:checked').length;
		const selectedDbTables = form.querySelectorAll('input[name="synchy_sync_selected_db_tables[]"]:checked').length;
		const confirmMessage = [
			config.strings.confirmSync || "Sync the previewed changes to the destination site now?",
			"",
			`Destination: ${destinationUrl || "Not set"}`,
			`Scopes: ${scopeLabels.join(", ") || "None"}`,
			`Selected preview items: ${selectedFileSections} file sections, ${selectedDbTables} DB tables`,
		].join("\n");

		if (!window.confirm(confirmMessage)) {
			return;
		}

		setBusy(true);
		currentJob = {
			status: "running",
			phase: "building_package",
			phaseLabel: config.strings.syncRunning || "Sync running",
			progress: 5,
			message: "Starting Sync...",
			filesCount: Number(latestPreview?.filesCount || 0),
			dbRows: Number(latestPreview?.dbRows || 0),
			stages: Array.isArray(config.defaultStages) ? config.defaultStages : [],
		};
		renderProgress(currentJob);
		window.setTimeout(pollSyncJob, 100);
		statusBadge.textContent = config.strings.syncingAction || "Syncing...";
		statusSummary.textContent = "Sync is running. Keep this tab open until it finishes.";

		try {
			const data = await sendAjax("synchy_run_sync_changes");
			currentJob = data.job || null;
			renderProgress(currentJob);
			renderStatus(data.status || {});
			applyScopeStatus(data.scopeStatus || null);
			clearPreview();
		} catch (error) {
			renderStatus({
				status: "error",
				message: error.message,
				at: new Date().toISOString(),
				lastSyncTime: "",
				filesSynced: 0,
				dbRowsSynced: 0,
				durationSeconds: 0,
				destinationUrl: destinationUrl || "",
				mode: "",
			});
		} finally {
			setBusy(false);
		}
	};

	form.addEventListener("input", (event) => {
		if (event.target?.matches("[data-synchy-preview-selection]")) {
			updateActionButtons();
			return;
		}

		if (
			event.target === destinationUrlInput
			|| event.target === usernameInput
			|| event.target === passwordInput
		) {
			connectionVerified = false;
		}

		updateTargetNote();
		updateConnectionControls();
		updateActionButtons();
	});
	form.addEventListener("change", (event) => {
		if (event.target?.matches("[data-synchy-preview-selection]")) {
			updateActionButtons();
			return;
		}

		if (
			event.target === destinationUrlInput
			|| event.target === usernameInput
			|| event.target === passwordInput
			|| event.target === verifySslInput
		) {
			connectionVerified = false;
		}

		updateTargetNote();
		updateConnectionControls();
		updateActionButtons();
	});
	testButton.addEventListener("click", runTestConnection);
	previewButton.addEventListener("click", runPreview);
	runButton.addEventListener("click", runSync);
	manualBaselineButton.addEventListener("click", runManualBaseline);

	updateTargetNote();
	updateScopeRows();
	renderProgress(currentJob);
	if (currentConnectionState?.status === "connected") {
		renderConnectionResult(currentConnectionState.remoteSite || {}, false);
	} else if (currentConnectionState?.status === "error") {
		renderConnectionResult({ message: currentConnectionState.message || (config.strings.connectionError || "Connection failed") }, true);
	}

	if (currentJob && currentJob.status === "running") {
		setBusy(true);
		window.setTimeout(pollSyncJob, 100);
		return;
	}

	updateConnectionControls();
	updateActionButtons();
})();
