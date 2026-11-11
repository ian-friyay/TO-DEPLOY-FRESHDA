/* FRESH-DA — PUBLIC FISH SCANNER
   Shows the AI result for an anonymous visitor. Nothing is saved to any
   account's history; only the image validation and analysis request run. */

document.addEventListener("DOMContentLoaded", function () {

  var uploadBox = document.getElementById("scanUpload");
  var preview = document.getElementById("scanPreview");
  var previewEmpty = document.getElementById("scanPreviewEmpty");
  var previewImage = document.getElementById("scanPreviewImage");
  var fileInput = document.getElementById("scanFileInput");
  var analyzeBtn = document.getElementById("scanAnalyzeBtn");
  var errorBox = document.getElementById("scanError");
  var errorText = document.getElementById("scanErrorText");
  var result = document.getElementById("scanResult");
  var againBtn = document.getElementById("scanAgainBtn");

  var csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    .getAttribute("content");

  var selectedFile = null;
  var submitting = false;

  function showError(message) {
    errorText.textContent = message;
    errorBox.classList.add("show");
  }

  function hideError() {
    errorBox.classList.remove("show");
  }

  function acceptFile(file) {
    if (!file) return;
    /* Uploading while the live preview runs closes the stream first, so the
       still preview is not hidden behind the video layer. */
    if (cameraStream) camStop();
    if (file.type.indexOf("image/") !== 0) {
      showError("Please Choose An Image File (JPG, PNG Or WebP).");
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      showError("The Image Must Be 10 MB Or Smaller.");
      return;
    }

    selectedFile = file;
    hideError();

    previewImage.src = URL.createObjectURL(file);
    previewImage.classList.remove("hidden");
    previewEmpty.classList.add("hidden");
    analyzeBtn.disabled = false;
  }

  function resetView() {
    selectedFile = null;
    submitting = false;
    fileInput.value = "";
    previewImage.src = "";
    previewImage.classList.add("hidden");
    previewEmpty.classList.remove("hidden");
    analyzeBtn.disabled = true;
    result.classList.add("hidden");
    hideError();
  }

  /* ================= UPLOAD WIRING ================= */

  preview.addEventListener("click", function () {
    if (cameraStream) return;
    fileInput.click();
  });

  fileInput.addEventListener("change", function () {
    acceptFile(this.files && this.files[0]);
  });

  /* Live camera, vendor-style: getUserMedia preview + capture photo. The
     native camera app (capture=input) stays as the fallback for browsers
     without getUserMedia or while the page is not a secure context. */
  var cameraBtn = document.getElementById("scanCameraBtn");
  var captureBtn = document.getElementById("scanCaptureBtn");
  var closeBtn = document.getElementById("scanCloseBtn");
  var captureInput = document.getElementById("scanCaptureInput");
  var cameraVideo = document.getElementById("scanCameraVideo");
  var liveBadge = document.getElementById("scanLiveBadge");
  var cameraCanvas = document.getElementById("scanCanvas");
  var cameraStream = null;

  function camAlive() {
    return !!cameraStream;
  }

  function camUi(live) {
    if (cameraBtn) cameraBtn.classList.toggle("hidden", live);
    if (captureBtn) captureBtn.classList.toggle("hidden", !live);
    if (closeBtn) closeBtn.classList.toggle("hidden", !live);
    if (liveBadge) liveBadge.classList.toggle("hidden", !live);
  }

  function camStop() {
    if (cameraStream) {
      cameraStream.getTracks().forEach(function (track) {
        track.stop();
      });
      cameraStream = null;
    }
    if (cameraVideo) {
      cameraVideo.srcObject = null;
      cameraVideo.classList.add("hidden");
    }
    camUi(false);
    if (!selectedFile) {
      previewImage.src = "";
      previewImage.classList.add("hidden");
      previewEmpty.classList.remove("hidden");
    }
  }

  function camStart() {
    if (camAlive()) return;
    var supported = !!(
      navigator.mediaDevices &&
      typeof navigator.mediaDevices.getUserMedia === "function"
    );

    if (!supported || !window.isSecureContext) {
      captureInput.click();
      return;
    }

    navigator.mediaDevices
      .getUserMedia({
        audio: false,
        video: {
          facingMode: "environment",
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      })
      .then(function (stream) {
        cameraStream = stream;
        cameraVideo.srcObject = stream;
        var playing = cameraVideo.play();
        if (playing && playing.catch) playing.catch(function () {});
        cameraVideo.classList.remove("hidden");
        previewEmpty.classList.add("hidden");
        analyzeBtn.disabled = true;
        result.classList.add("hidden");
        camUi(true);
      })
      .catch(function () {
        captureInput.click();
      });
  }

  function camCapture() {
    if (!cameraStream || !cameraVideo.videoWidth) return;
    if (!cameraCanvas || !cameraCanvas.getContext) return;

    var scale = Math.min(1, 1280 / cameraVideo.videoWidth);
    cameraCanvas.width = Math.round(cameraVideo.videoWidth * scale);
    cameraCanvas.height = Math.round(cameraVideo.videoHeight * scale);
    cameraCanvas.getContext("2d").drawImage(
      cameraVideo,
      0,
      0,
      cameraCanvas.width,
      cameraCanvas.height
    );

    cameraCanvas.toBlob(
      function (blob) {
        if (!blob) return;
        var file = new File([blob], "capture-" + Date.now() + ".jpg", {
          type: "image/jpeg",
        });
        camStop();
        acceptFile(file);
      },
      "image/jpeg",
      0.9
    );
  }

  if (cameraBtn) {
    cameraBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      camStart();
    });
  }

  if (captureBtn) {
    captureBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      camCapture();
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener("click", function (e) {
      e.stopPropagation();
      camStop();
    });
  }

  captureInput.addEventListener("change", function () {
    acceptFile(this.files && this.files[0]);
  });

  ["dragover", "dragleave", "drop"].forEach(function (type) {
    preview.addEventListener(type, function (e) {
      e.preventDefault();
      preview.classList.toggle("drag", type === "dragover");
      if (type === "drop") {
        acceptFile(e.dataTransfer && e.dataTransfer.files[0]);
      }
    });
  });

  /* ================= ANALYSIS ================= */

  function fill(text, value) {
    var el = document.getElementById(text);
    if (el) el.textContent = value;
  }

  analyzeBtn.addEventListener("click", function () {
    if (submitting || !selectedFile) return;
    submitting = true;

    hideError();
    analyzeBtn.disabled = true;
    analyzeBtn.innerHTML =
      '<i class="fa-solid fa-wand-magic-sparkles"></i> Analyzing...';

    var body = new FormData();
    body.append("image", selectedFile);

    fetch("/scan/analyze", {
      method: "POST",
      headers: {
        "X-CSRF-TOKEN": csrfToken,
        Accept: "application/json",
      },
      body: body,
    })
      .then(function (response) {
        if (response.status === 429) {
          throw new Error("Too Many Requests — Please Wait A Minute And Try Again.");
        }
        if (!response.ok) {
          return response.json().then(function (err) {
            var msg = (err && err.errors && err.errors.image && err.errors.image[0])
              || (err && err.message)
              || "The Analysis Could Not Run. Please Try Again.";
            throw new Error(msg);
          }).catch(function (fallbackErr) {
            throw new Error(fallbackErr.message || "The Analysis Could Not Run. Please Try Again.");
          });
        }
        return response.json();
      })
      .then(function (data) {
        var freshnessLabels = {
          fresh: "Fresh",
          moderate: "Moderately Fresh",
          not_fresh: "Not Fresh",
        };
        var bands = {
          high: "High Confidence",
          moderate: "Moderate Confidence",
          low: "Low Confidence — Try a Clearer Photo",
        };

        fill("scanResultMessage", data.message);
        fill("scanResultName", data.name);
        fill("scanResultScientific", data.species_label);
        var badge = document.getElementById("scanResultFreshness");
        if (badge) {
          badge.textContent =
            freshnessLabels[data.freshness] ||
            String(data.freshness_percentage) + "%";
          badge.className =
            "freshness-badge " + (data.cls === "bad" ? "bad" : data.cls);
        }
        var bar = document.getElementById("scanResultFreshnessBar");
        if (bar) {
          bar.style.width = data.freshness_percentage + "%";
          bar.className = data.cls;
        }
        fill(
          "scanResultFreshnessConf",
          "Confidence: " + Math.round(data.freshness_confidence * 100) + "%"
        );
        fill(
          "scanResultConfidence",
          Math.round(data.species_confidence * 100) + "%"
        );
        fill("scanResultBand", bands[data.confidence_band] || "");

        result.classList.remove("hidden");
        result.scrollIntoView({ behavior: "smooth", block: "nearest" });
      })
      .catch(function (error) {
        var modal = document.getElementById("publicScanModalOverlay");
        var modalText = document.getElementById("publicScanModalText");
        if (error && error.name === "TypeError") {
          showError(
            "Could not reach the scan server. Check your connection or try again."
          );
          return;
        }
        var isFishError = error.message && (
          error.message.toLowerCase().indexOf("fish") !== -1 ||
          error.message.toLowerCase().indexOf("unclear") !== -1
        );

        if (modal && isFishError) {
          if (modalText) {
            modalText.textContent = error.message.indexOf("%") === -1
              ? error.message
              : "We couldn't clearly detect a fish in the scanned image. Please position the fish in clear lighting and scan again.";
          }
          modal.style.display = "flex";
        } else {
          showError(error.message || "The Analysis Could Not Run.");
        }
      })
      .finally(function () {
        analyzeBtn.disabled = !selectedFile;
        analyzeBtn.innerHTML =
          '<i class="fa-solid fa-wand-magic-sparkles"></i> Analyze Fish';
        submitting = false;
      });
  });

  var modalRetryBtn = document.getElementById("publicScanModalRetryBtn");
  var modalOverlay = document.getElementById("publicScanModalOverlay");
  if (modalRetryBtn && modalOverlay) {
    modalRetryBtn.addEventListener("click", function () {
      modalOverlay.style.display = "none";
      resetView();
      var camBtn = document.getElementById("scanCameraBtn");
      if (camBtn && !camBtn.classList.contains("hidden")) {
        camBtn.click();
      } else {
        fileInput.click();
      }
    });
    modalOverlay.addEventListener("click", function (e) {
      if (e.target === this) this.style.display = "none";
    });
  }

  if (againBtn) {
    againBtn.addEventListener("click", resetView);
  }
});
