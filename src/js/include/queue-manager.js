import { updateResultFailure, updateResult } from "./render";
import $ from "jquery";
import { hideDownload, showDownload } from "./download";
import { __, _n, sprintf } from "@wordpress/i18n";

const progress = $(".wpe-progress-active");
const progressCount = $(".wpe-pcc-progress-count");

export function initQueue(itemsToScan, mode) {
  // Reset the queue.
  window.phpcompat.queue = [];
  window.phpcompat.results = [];
  window.phpcompat.total = 0;
  window.phpcompat.completed = 0;

  clearTimeout(window.phpcompat.ticker);
  if (window.phpcompat.xhr) {
    window.phpcompat.xhr.abort();
  }

  hideDownload();
  resetProgress();
  $(".wpe-pcc-information").hide();
  $("#wpe-pcc-summary").empty();
  $("#testResults").val("");

  itemsToScan.plugins.forEach((plugin) => {
    if ("yes" === plugin.active || "no" === mode || "choose" === mode) {
      window.phpcompat.queue.push({
        ...plugin,
        type: "plugin",
      });
    }
  });

  itemsToScan.themes.forEach((theme) => {
    if ("yes" === theme.active || "no" === mode || "choose" === mode) {
      window.phpcompat.queue.push({
        ...theme,
        type: "theme",
      });
    }
  });

  window.phpcompat.total = window.phpcompat.queue.length;
}

/**
 * Limit the queue to the given "type_slug" IDs (used in "choose" mode).
 */
export function limitQueue(ids) {
  window.phpcompat.queue = window.phpcompat.queue.filter((job) =>
    ids.includes(`${job.type}_${job.slug}`)
  );
  window.phpcompat.total = window.phpcompat.queue.length;
}

export function executeJob(job, cb) {
  var endpoint = `https://wptide.org/api/v1/audit/wporg/${job.type}/${job.slug}/${job.version}?reports=phpcs_phpcompatibilitywp`;

  // Only allow 1 concurrent request at a time
  if (
    false === window.phpcompat.xhr ||
    [0, 4].includes(window.phpcompat.xhr.readyState)
  ) {
    window.phpcompat.xhr = $.ajax(endpoint, {
      dataType: "json",
      beforeSend: () => {
        const resultItem = $(`#${job.type}_${job.slug}`);
        if (!resultItem.find(".spinner").length) {
          const spinner = $('<span class="spinner"></span>');
          spinner.show().appendTo(resultItem);
        }
      },
    })
      .done((response) => {
        if ("complete" === response.status) {
          window.phpcompat.results.push({ ...job, ...response });
          updateResult(response, job);
          updateProgress();
        } else if ("pending" === response.status) {
          const now = new Date();
          console.log("Report is pending, retry in 5 seconds");
          // Retry in 5 seconds.
          job.retryAt = new Date(now.getTime() + 5000);
          window.phpcompat.queue.push(job);
        } else {
          updateProgress();
          // Unexpected behaviour. Stop scanning and display current status.
          updateResultFailure(response, job);
        }
      })
      .fail((jqXHR) => {
        updateProgress();

        // If connection was lost during scan, show error message.
        if (!jqXHR.responseJSON && 0 === jqXHR.status && 0 === jqXHR.readyState) {
          updateResultFailure(
            {
              status: "failed",
              message: __(
                "The audit of this code was interrupted, please scan again.",
                "php-compatibility-checker"
              ),
            },
            job
          );
        } else if (404 === jqXHR.status) {
          // Tide only audits plugins and themes hosted on WordPress.org.
          updateResultFailure(
            {
              status: "skipped",
              message: sprintf(
                /* translators: 1: plugin or theme name, 2: version number. */
                __(
                  "%1$s %2$s could not be scanned. Only plugins and themes available on WordPress.org can be checked, so premium, custom, or modified versions are skipped.",
                  "php-compatibility-checker"
                ),
                job.name,
                job.version
              ),
            },
            job
          );
        } else {
          updateResultFailure(
            jqXHR.responseJSON ?? {
              status: jqXHR.status,
              message: jqXHR.responseText,
            },
            job
          );
        }
      })
      .always(() => {
        cb();
      });
  }
}

export function runNextJob() {
  if (window.phpcompat.queue.length === 0) {
    resetProgress();
    showDownload();
    renderSummary();

    // Only offer help when something actually failed.
    if ($('[data-result="problems"]').length) {
      $(".wpe-pcc-information").show();
    }
    return;
  }

  const now = new Date();

  // Pick up next job from queue.
  var job = window.phpcompat.queue.shift();

  // The job is new or the time has come.
  if ("undefined" === typeof job.retryAt || job.retryAt <= now) {
    // Run the job now.
    executeJob(job, () => {
      runNextJob();
    });
  } else {
    // Or put it back to the end of queue.
    window.phpcompat.queue.push(job);
    window.phpcompat.ticker = setTimeout(runNextJob, 1000);
  }
}

export function updateProgress() {
  window.phpcompat.completed++;
  progress.show();
  progressCount.text(`${window.phpcompat.completed} of ${window.phpcompat.total}`);
}

function resetProgress() {
  $(".wpe-pcc-spinner").hide();
  progressCount.text("");
  progress.hide();
}

/**
 * One-line summary of the finished scan, e.g. "3 pass · 2 have problems".
 */
function renderSummary() {
  const count = (result) => $(`#wpe_pcc_results [data-result="${result}"]`).length;
  const parts = [
    {
      key: "success",
      n: count("success"),
      /* translators: %d: number of plugins and themes. */
      text: (n) => sprintf(_n("%d passes", "%d pass", n, "php-compatibility-checker"), n),
    },
    {
      key: "problems",
      n: count("problems"),
      /* translators: %d: number of plugins and themes. */
      text: (n) => sprintf(_n("%d has problems", "%d have problems", n, "php-compatibility-checker"), n),
    },
    {
      key: "skipped",
      n: count("skipped"),
      /* translators: %d: number of plugins and themes. */
      text: (n) => sprintf(_n("%d not checked", "%d not checked", n, "php-compatibility-checker"), n),
    },
    {
      key: "failed",
      n: count("failed"),
      /* translators: %d: number of plugins and themes. */
      text: (n) => sprintf(_n("%d failed to scan", "%d failed to scan", n, "php-compatibility-checker"), n),
    },
  ].filter((part) => part.n > 0);

  const summary = $("#wpe-pcc-summary").empty();
  parts.forEach((part) => {
    $("<span>")
      .addClass(`wpe-pcc-summary-item wpe-pcc-summary-${part.key}`)
      .text(part.text(part.n))
      .appendTo(summary);
  });
}
