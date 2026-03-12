(function () {
	const config = window.synchyExportConfig;

	if (!config) {
		return;
	}

	const form = document.querySelector("[data-synchy-export-form]");
	const runButton = document.querySelector("[data-synchy-run-export]");
	const progress = document.querySelector("[data-synchy-progress]");
	const progressBar = document.querySelector("[data-synchy-progress-bar]");
	const progressPhase = document.querySelector("[data-synchy-progress-phase]");
	const progressPercent = document.querySelector("[data-synchy-progress-percent]");
	const progressMessage = document.querySelector("[data-synchy-progress-message]");
	const progressDetail = document.querySelector("[data-synchy-progress-detail]");
	const packageNameInput = document.querySelector("[data-synchy-package-name]");
	const outputDirectoryInput = document.querySelector("[data-synchy-output-directory]");
	const defaultPathButton = document.querySelector("[data-synchy-use-default]");
	const archivePreview = document.querySelector("[data-synchy-archive-preview]");
	const installerPreview = document.querySelector("[data-synchy-installer-preview]");
	const manifestPreview = document.querySelector("[data-synchy-manifest-preview]");
	const browseButton = document.querySelector("[data-synchy-browse]");
	const modal = document.querySelector("[data-synchy-directory-modal]");
	const modalPath = document.querySelector("[data-synchy-directory-current]");
	const modalList = document.querySelector("[data-synchy-directory-list]");
	const modalCloseButtons = document.querySelectorAll("[data-synchy-modal-close]");
	const modalUpButton = document.querySelector("[data-synchy-directory-up]");
	const modalSelectButton = document.querySelector("[data-synchy-directory-select]");

	if (!form || !runButton || !packageNameInput || !outputDirectoryInput) {
		return;
	}

	let currentJob = config.currentJob || null;
	let currentBrowsePath = outputDirectoryInput.value || "./";

	const slugifyPackageName = (value) => {
		const trimmed = value.trim();
		const fallback = "synchy-export";
		const withoutExt = trimmed.replace(/\.(zip|php|json)$/gi, "");
		const slug = withoutExt
			.replace(/[^A-Za-z0-9._ -]+/g, "-")
			.trim()
			.replace(/\s+/g, "-")
			.replace(/-+/g, "-")
			.replace(/^[-_.]+|[-_.]+$/g, "")
			.toLowerCase();

		return slug || fallback;
	};

	const updatePackagePreview = () => {
		const base = slugifyPackageName(packageNameInput.value || "");

		if (archivePreview) {
			archivePreview.textContent = `${base}.zip`;
		}

		if (installerPreview) {
			installerPreview.textContent = `${base}-installer.php`;
		}

		if (manifestPreview) {
			manifestPreview.textContent = `${base}-manifest.json`;
		}
	};

	const setRunningState = (isRunning) => {
		runButton.disabled = isRunning;
		runButton.textContent = isRunning ? "Exporting..." : "Run Full Export";
	};

	const setProgressDetail = (job) => {
		if (!progressDetail) {
			return;
		}

		const processed = Number(job.fileCursor || 0).toLocaleString();
		const total = Number(job.fileCount || 0).toLocaleString();
		progressDetail.textContent = `${config.strings.filesProcessed} ${processed} / ${total}`;
	};

	const renderProgress = (job) => {
		if (!progress || !job) {
			return;
		}

		progress.classList.remove("is-hidden");

		if (progressBar) {
			progressBar.style.width = `${job.progress || 0}%`;
		}

		if (progressPhase) {
			progressPhase.textContent = job.phaseLabel || "Preparing";
		}

		if (progressPercent) {
			progressPercent.textContent = `${job.progress || 0}%`;
		}

		if (progressMessage) {
			progressMessage.textContent = job.message || "";
		}

		setProgressDetail(job);
	};

	const request = async (payload) => {
		const body = new FormData();

		Object.entries(payload).forEach(([key, value]) => {
			if (value !== undefined && value !== null) {
				body.append(key, value);
			}
		});

		const response = await fetch(config.ajaxUrl, {
			method: "POST",
			credentials: "same-origin",
			body,
		});

		const json = await response.json();

		if (!response.ok || !json.success) {
			const message = json && json.data && json.data.message ? json.data.message : config.strings.unknownError;
			throw new Error(message);
		}

		return json.data;
	};

	const serializeExportOptions = () => {
		const formData = new FormData(form);
		const payload = {};

		formData.forEach((value, key) => {
			payload[key] = value;
		});

		payload.action = "synchy_start_export";
		payload.nonce = config.nonce;

		return payload;
	};

	const pollJob = async (jobId) => {
		try {
			const data = await request({
				action: "synchy_continue_export",
				nonce: config.nonce,
				job_id: jobId,
			});
			currentJob = data.job;
			renderProgress(currentJob);

			if (currentJob.status === "running") {
				window.setTimeout(() => pollJob(jobId), 600);
				return;
			}

			if (currentJob.status === "complete") {
				window.setTimeout(() => window.location.reload(), 700);
				return;
			}

			setRunningState(false);
		} catch (error) {
			if (progressMessage) {
				progressMessage.textContent = error.message;
			}
			setRunningState(false);
		}
	};

	const openModal = () => {
		if (!modal) {
			return;
		}

		modal.classList.remove("is-hidden");
		modal.setAttribute("aria-hidden", "false");
	};

	const closeModal = () => {
		if (!modal) {
			return;
		}

		modal.classList.add("is-hidden");
		modal.setAttribute("aria-hidden", "true");
	};

	const renderDirectoryList = (payload) => {
		if (!modalList || !modalPath || !modalUpButton) {
			return;
		}

		currentBrowsePath = payload.currentPath || "./";
		modalPath.textContent = currentBrowsePath;
		modalUpButton.disabled = !payload.parentPath;
		modalUpButton.dataset.path = payload.parentPath || "";
		modalList.innerHTML = "";

		(payload.directories || []).forEach((directory) => {
			const button = document.createElement("button");
			button.type = "button";
			button.className = "synchy-directory-list__item";
			button.dataset.path = directory.path;
			button.textContent = directory.name;
			modalList.appendChild(button);
		});

		if (!payload.directories || payload.directories.length === 0) {
			const empty = document.createElement("p");
			empty.className = "synchy-directory-list__empty";
			empty.textContent = "No subfolders here.";
			modalList.appendChild(empty);
		}
	};

	const loadBrowsePath = async (path) => {
		try {
			const data = await request({
				action: "synchy_browse_export_directories",
				nonce: config.nonce,
				path,
			});
			renderDirectoryList(data);
		} catch (error) {
			if (modalList) {
				modalList.innerHTML = `<p class="synchy-directory-list__empty">${error.message}</p>`;
			}
		}
	};

	updatePackagePreview();

	packageNameInput.addEventListener("input", updatePackagePreview);

	if (defaultPathButton) {
		defaultPathButton.addEventListener("click", () => {
			outputDirectoryInput.value = defaultPathButton.dataset.defaultPath || "wp-content/uploads/synchy-backups/";
		});
	}

	runButton.addEventListener("click", async () => {
		try {
			setRunningState(true);
			renderProgress({
				phaseLabel: "Preparing",
				progress: 1,
				message: "Starting export job...",
				fileCursor: 0,
				fileCount: 0,
			});

			const data = await request(serializeExportOptions());
			currentJob = data.job;
			renderProgress(currentJob);
			pollJob(currentJob.id);
		} catch (error) {
			renderProgress({
				phaseLabel: "Error",
				progress: 100,
				message: error.message,
				fileCursor: 0,
				fileCount: 0,
			});
			setRunningState(false);
		}
	});

	if (browseButton && modal) {
		browseButton.addEventListener("click", () => {
			openModal();
			loadBrowsePath(outputDirectoryInput.value || "./");
		});

		modalList.addEventListener("click", (event) => {
			const target = event.target;

			if (!(target instanceof HTMLElement) || !target.matches("[data-path]")) {
				return;
			}

			loadBrowsePath(target.dataset.path || "./");
		});

		modalUpButton.addEventListener("click", () => {
			if (modalUpButton.dataset.path) {
				loadBrowsePath(modalUpButton.dataset.path);
			}
		});

		modalSelectButton.addEventListener("click", () => {
			outputDirectoryInput.value = currentBrowsePath;
			closeModal();
		});

		modalCloseButtons.forEach((button) => {
			button.addEventListener("click", closeModal);
		});
	}

	if (currentJob && currentJob.status === "running" && currentJob.id) {
		setRunningState(true);
		renderProgress(currentJob);
		pollJob(currentJob.id);
	}
})();
