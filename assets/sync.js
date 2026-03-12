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

	if (
		!form ||
		!testButton ||
		!previewButton ||
		!runButton ||
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

	const setBusy = (isBusy, buttonText = "") => {
		busy = isBusy;

		if (saveButton) {
			saveButton.disabled = isBusy;
		}

		testButton.disabled = isBusy;
		previewButton.disabled = isBusy;
		runButton.disabled = isBusy || latestPreview === null;
		runButton.textContent = isBusy ? buttonText || (config.strings.syncingAction || "Syncing...") : (config.strings.syncAction || "Sync Changes");
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
		runButton.disabled = true;
		renderPreview(null);
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

	const runTestConnection = async () => {
		setBusy(true, config.strings.syncAction || "Sync Changes");

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
		setBusy(true, config.strings.syncAction || "Sync Changes");

		try {
			const data = await sendAjax("synchy_preview_sync_changes");
			latestPreview = data.preview || null;
			renderPreview(latestPreview);
			runButton.disabled = latestPreview === null;
		} catch (error) {
			latestPreview = null;
			runButton.disabled = true;
			previewBadge.textContent = config.strings.previewError || "Preview failed";
			previewMessage.textContent = error.message;
			renderMeta(previewMeta, []);
		} finally {
			setBusy(false);
		}
	};

	const runSync = async () => {
		if (latestPreview === null) {
			return;
		}

		if (!window.confirm(config.strings.confirmSync || "Sync the previewed changes to the destination site now?")) {
			return;
		}

		setBusy(true, config.strings.syncingAction || "Syncing...");
		statusBadge.textContent = config.strings.syncingAction || "Syncing...";
		statusMessage.textContent = "Sync is running. Keep this tab open until it finishes.";

		try {
			const data = await sendAjax("synchy_run_sync_changes");
			const status = data.status || {};
			renderStatus(status);
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
				destinationUrl: "",
				mode: "",
			});
		} finally {
			setBusy(false);
		}
	};

	form.addEventListener("input", clearPreview);
	form.addEventListener("change", clearPreview);
	testButton.addEventListener("click", runTestConnection);
	previewButton.addEventListener("click", runPreview);
	runButton.addEventListener("click", runSync);
})();
