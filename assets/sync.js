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
	const connectionPanel = document.querySelector("[data-synchy-sync-connection-result]");
	const connectionBadge = document.querySelector("[data-synchy-sync-connection-badge]");
	const connectionMessage = document.querySelector("[data-synchy-sync-connection-message]");
	const connectionMeta = document.querySelector("[data-synchy-sync-connection-meta]");
	const previewBadge = document.querySelector("[data-synchy-sync-preview-badge]");
	const previewMessage = document.querySelector("[data-synchy-sync-preview-message]");
	const previewMeta = document.querySelector("[data-synchy-sync-preview-meta]");
	const statusBadge = document.querySelector("[data-synchy-sync-status-badge]");
	const statusMessage = document.querySelector("[data-synchy-sync-status-message]");
	const statusMeta = document.querySelector("[data-synchy-sync-status-meta]");
	const destinationUrlInput = document.querySelector("[data-synchy-sync-url]");
	const targetNote = document.querySelector("[data-synchy-sync-target-note]");
	const scopeInputs = Array.from(document.querySelectorAll("[data-synchy-sync-scope]"));

	if (
		!form ||
		!testButton ||
		!previewButton ||
		!runButton ||
		!manualBaselineButton ||
		!previewBadge ||
		!previewMessage ||
		!previewMeta ||
		!statusBadge ||
		!statusMessage ||
		!statusMeta
	) {
		return;
	}

	let latestPreview = null;
	let busy = false;
	let pendingBaselineScopeIds = new Set((config.scopeStatus?.pendingBaselineScopeIds || []).map(String));

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

	const getSelectedScopeIds = () =>
		scopeInputs
			.filter((input) => input.checked)
			.map((input) => String(input.dataset.scopeId || ""));

	const getSelectedScopeLabels = () =>
		scopeInputs
			.filter((input) => input.checked)
			.map((input) => input.closest(".synchy-scope-card")?.querySelector("strong")?.textContent?.trim() || "")
			.filter(Boolean);

	const getHasSelection = () => getSelectedScopeIds().length > 0;

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

	const updateScopeCards = () => {
		scopeInputs.forEach((input) => {
			const card = input.closest(".synchy-scope-card");
			const status = card?.querySelector(".synchy-scope-card__status");
			const scopeId = String(input.dataset.scopeId || "");
			const pending = pendingBaselineScopeIds.has(scopeId);

			if (!card || !status) {
				return;
			}

			card.classList.toggle("is-pending", pending);
			card.classList.toggle("is-complete", !pending);
			status.textContent = pending ? "Needs baseline" : "Baseline done";
		});
	};

	const updateActionButtons = () => {
		const hasSelection = getHasSelection();
		const runLabel = getRunActionLabel();

		previewButton.disabled = busy || !hasSelection;
		runButton.disabled = busy || latestPreview === null || !hasSelection;
		runButton.textContent = busy ? (config.strings.syncingAction || "Syncing...") : runLabel;

		if (manualBaselineButton) {
			const showManualBaseline = hasSelection && getHasPendingBaselineSelection();
			manualBaselineButton.style.display = showManualBaseline ? "" : "none";
			manualBaselineButton.disabled = busy || !showManualBaseline;
		}
	};

	const setBusy = (isBusy) => {
		busy = isBusy;

		if (saveButton) {
			saveButton.disabled = isBusy;
		}

		testButton.disabled = isBusy;
		updateActionButtons();
	};

	const applyScopeStatus = (scopeStatus) => {
		if (!scopeStatus || !Array.isArray(scopeStatus.pendingBaselineScopeIds)) {
			return;
		}

		pendingBaselineScopeIds = new Set(scopeStatus.pendingBaselineScopeIds.map(String));
		updateScopeCards();
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

	const renderPreview = (preview) => {
		if (!preview) {
			previewBadge.textContent = "";
			previewMessage.textContent = config.strings.previewDefault || "Run Preview Changes to review changed files and database rows before syncing.";
			renderMeta(previewMeta, []);
			return;
		}

		const mode = String(preview.mode || "delta").toLowerCase() === "baseline"
			? (config.strings.baseline || "Baseline")
			: (config.strings.delta || "Delta");
		const filesCount = Number(preview.filesCount || 0);
		const dbRows = Number(preview.dbRows || 0);
		const tableCounts = preview.tableCounts || {};
		const sampleFiles = Array.isArray(preview.filePaths) ? preview.filePaths.slice(0, 8) : [];
		const selectedScopes = Array.isArray(preview.selectedScopeLabels) ? preview.selectedScopeLabels : [];
		const pendingScopes = Array.isArray(preview.pendingBaselineLabels) ? preview.pendingBaselineLabels : [];
		const tableSummary = Object.entries(tableCounts)
			.filter((entry) => Number(entry[1] || 0) > 0)
			.map((entry) => `${escapeHtml(entry[0])} (${escapeHtml(entry[1])})`)
			.join("<br>");
		const fileSummary = sampleFiles.map((path) => escapeHtml(path)).join("<br>");

		previewBadge.textContent = mode;

		if (filesCount === 0 && dbRows === 0) {
			previewMessage.textContent = "No changes detected since the last successful Sync.";
		} else {
			previewMessage.textContent = `${filesCount} files and ${dbRows} DB rows will sync to the destination site.`;
		}

		renderMeta(previewMeta, [
			{ label: config.strings.lastSync || "Last successful Sync", value: formatDateTime(preview.lastSyncTime || "") },
			{ label: "Mode", value: mode },
			{ label: config.strings.selectedScopes || "Selected scopes", value: selectedScopes.join(", ") || "None" },
			{ label: config.strings.pendingBaseline || "Still need baseline", value: pendingScopes.join(", ") || "None" },
			{ label: config.strings.files || "Files", value: filesCount.toLocaleString() },
			{ label: config.strings.dbRows || "DB rows", value: dbRows.toLocaleString() },
			{ label: config.strings.tableUpdates || "Table updates", value: tableSummary || "None", html: true },
			{ label: config.strings.sampleFiles || "Sample files", value: fileSummary || "None", html: true },
		]);
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

	const renderStatus = (status) => {
		statusBadge.textContent = getStatusBadge(status);
		statusMessage.textContent = getStatusMessage(status);

		renderMeta(statusMeta, [
			{ label: config.strings.lastRun || "Last run", value: formatDateTime(status?.at || "") },
			{ label: config.strings.lastSync || "Last successful Sync", value: formatDateTime(status?.lastSyncTime || "") },
			{ label: config.strings.destination || "Destination", value: status?.destinationUrl || "" },
			{ label: config.strings.selectedScopes || "Selected scopes", value: Array.isArray(status?.selectedScopeLabels) ? status.selectedScopeLabels.join(", ") : "" },
			{ label: config.strings.files || "Files", value: Number(status?.filesSynced || 0).toLocaleString() },
			{ label: config.strings.dbRows || "DB rows", value: Number(status?.dbRowsSynced || 0).toLocaleString() },
			{
				label: "Mode",
				value: String(status?.mode || "").toLowerCase() === "baseline"
					? (config.strings.baseline || "Baseline")
					: String(status?.mode || "") === ""
						? ""
						: (config.strings.delta || "Delta"),
			},
			{ label: config.strings.duration || "Duration", value: formatDuration(status?.durationSeconds || 0) },
		]);
	};

	const clearPreview = () => {
		latestPreview = null;
		renderPreview(null);
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

	const requireSelection = () => {
		if (getHasSelection()) {
			return true;
		}

		previewBadge.textContent = config.strings.previewError || "Preview failed";
		previewMessage.textContent = config.strings.selectAtLeastOneScope || "Select at least one file or database scope first.";
		renderMeta(previewMeta, []);
		return false;
	};

	const runTestConnection = async () => {
		setBusy(true);

		try {
			const data = await sendAjax("synchy_test_sync_connection");
			renderConnectionResult(data.remoteSite || {}, false);
		} catch (error) {
			renderConnectionResult({ message: error.message }, true);
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
			const data = await sendAjax("synchy_preview_sync_changes");
			latestPreview = data.preview || null;
			applyScopeStatus(data.scopeStatus || null);
			renderPreview(latestPreview);
		} catch (error) {
			latestPreview = null;
			previewBadge.textContent = config.strings.previewError || "Preview failed";
			previewMessage.textContent = error.message;
			renderMeta(previewMeta, []);
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
		statusMessage.textContent = "Saving the selected manual baseline state.";

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
		const confirmMessage = [
			config.strings.confirmSync || "Sync the previewed changes to the destination site now?",
			"",
			`Destination: ${destinationUrl || "Not set"}`,
			`Scopes: ${scopeLabels.join(", ") || "None"}`,
		].join("\n");

		if (!window.confirm(confirmMessage)) {
			return;
		}

		setBusy(true);
		statusBadge.textContent = config.strings.syncingAction || "Syncing...";
		statusMessage.textContent = "Sync is running. Keep this tab open until it finishes.";

		try {
			const data = await sendAjax("synchy_run_sync_changes");
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

	form.addEventListener("input", () => {
		clearPreview();
		updateTargetNote();
		updateActionButtons();
	});
	form.addEventListener("change", () => {
		clearPreview();
		updateTargetNote();
		updateScopeCards();
		updateActionButtons();
	});
	testButton.addEventListener("click", runTestConnection);
	previewButton.addEventListener("click", runPreview);
	runButton.addEventListener("click", runSync);
	manualBaselineButton.addEventListener("click", runManualBaseline);

	updateTargetNote();
	updateScopeCards();
	updateActionButtons();
})();
