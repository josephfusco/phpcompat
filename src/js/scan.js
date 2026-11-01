import "../scss/scan.scss";
import jQuery from "jquery";
import { initQueue, runNextJob, limitQueue } from "./include/queue-manager";
import { initResults, getSelectedIds } from "./include/render";
import { downloadReport } from "./include/download";

(function ($) {
  if ("undefined" === typeof checkerList) {
    return;
  }

  window.phpcompat = {};
  window.phpcompat.queue = [];
  window.phpcompat.total = 0;
  window.phpcompat.completed = 0;
  window.phpcompat.xhr = false;
  window.phpcompat.ticker = false;
  window.phpcompat.results = [];

  const activeOnlySwitch = $("input[type=radio][name=active_plugins]");
  const runButton = $("#runButton");

  init(checkerList);

  activeOnlySwitch.on("change", function () {
    init(checkerList);
    runButton.prop("disabled", false);
    updateRunButton();
    disableDeveloperMode();
  });

  $("#cleanupButton").on("click", function (event) {
    event.preventDefault();
    runButton.prop("disabled", false);
    disableDeveloperMode();
    init(checkerList);
    updateRunButton();
  });

  runButton.on("click", function (event) {
    event.preventDefault();

    if ("choose" === currentMode()) {
      const ids = getSelectedIds();
      if (!ids.length) {
        return;
      }
      limitQueue(ids);

      // Drop unselected items from the list and lock the selection.
      $(".wpe-pcc-select").not(":checked").closest(".wpe-pcc-alert").remove();
      $(".wpe-pcc-select").prop("disabled", true);
      $(".wpe-pcc-select-actions").remove();
      $("#wpe_pcc_results h3").each(function () {
        if (!$(this).nextUntil("h3", ".wpe-pcc-alert").length) {
          $(this).remove();
        }
      });
    }

    runNextJob();
    $(".wpe-pcc-spinner").show();
    $(this).prop("disabled", true);
  });

  $(document).on("change", ".wpe-pcc-select", updateRunButton);

  $(document).on("click", ".wpe-pcc-select-actions a", function (event) {
    event.preventDefault();
    $(".wpe-pcc-select").prop("checked", "all" === $(this).data("select"));
    updateRunButton();
  });

  $(document).on("click", ".wpe-pcc-php-version-errors", function (event) {
    event.preventDefault();
    const button = $(this);
    const phpVersion = button.data("php-version");
    const report = button
      .closest(".wpe-pcc-alert")
      .find(`.wpe-pcc-php-version-report[data-php-version="${phpVersion}"]`);
    const opening = !report.is(":visible");

    // Only one report open at a time.
    $(".wpe-pcc-php-version-report").not(report).hide();
    $(".wpe-pcc-php-version-errors").not(button).attr("aria-expanded", "false");

    report.toggle(opening);
    button.attr("aria-expanded", opening ? "true" : "false");
  });

  $(document).on(
    "click",
    ".wpe-pcc-php-version-report-close",
    function (event) {
      event.preventDefault();
      const report = $(this).closest(".wpe-pcc-php-version-report");
      report.hide();
      report
        .closest(".wpe-pcc-alert")
        .find(`.wpe-pcc-php-version-errors[data-php-version="${report.data("php-version")}"]`)
        .attr("aria-expanded", "false")
        .trigger("focus");
    }
  );

  $("#developermode").on("change", function (event) {
    if ($(this).is(":checked")) {
      $("#developerMode").show();
      $("#wpe_pcc_results").hide();
    } else {
      $("#developerMode").hide();
      $("#wpe_pcc_results").show();
    }
  });

  $("#downloadReport").on("click", function (event) {
    event.preventDefault();
    downloadReport();
  });

  function currentMode() {
    return $("input[type=radio][name=active_plugins]:checked").val();
  }

  function init(itemsToScan) {
    const mode = currentMode();
    initQueue(itemsToScan, mode);
    initResults(itemsToScan, mode);
  }

  // In "choose" mode, only allow scanning when something is selected.
  function updateRunButton() {
    if ("choose" === currentMode()) {
      runButton.prop("disabled", !getSelectedIds().length);
    }
  }

  function disableDeveloperMode() {
    $("#developermode").prop("checked", false);
    $("#developerMode").hide();
    $("#wpe_pcc_results").show();
  }
})(jQuery);
