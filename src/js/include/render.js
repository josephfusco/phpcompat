import Mustache from "mustache";
import $ from "jquery";
import { compareVersions } from "compare-versions";
import { __, sprintf } from "@wordpress/i18n";

export function initResults(itemsToScan, mode) {
  const container = $("#wpe_pcc_results");
  const template = $("#result-template").html().toString();
  const choosing = "choose" === mode;
  container.empty();

  if (choosing) {
    container.append(
      `<p class="wpe-pcc-select-actions"><a href="#" data-select="all">${__(
        "Select all",
        "php-compatibility-checker"
      )}</a> | <a href="#" data-select="none">${__(
        "Select none",
        "php-compatibility-checker"
      )}</a></p>`
    );
  }

  const groups = [
    { items: itemsToScan.plugins, type: "plugin", heading: __("Plugins", "php-compatibility-checker") },
    { items: itemsToScan.themes, type: "theme", heading: __("Themes", "php-compatibility-checker") },
  ];

  groups.forEach(({ items, type, heading }) => {
    if (!items.length) {
      return;
    }

    container.append("<h3>" + heading + "</h3>");
    items.forEach((item) => {
      if (choosing || "yes" === item.active || "no" === mode) {
        const view = {
          ...item,
          type,
          status: "pending",
          selectable: choosing,
          checked: "yes" === item.active,
        };

        container.append(Mustache.render(template, view));
      }
    });
  });

  $(".wpe-pcc-results").show();
}

/**
 * IDs ("type_slug") of the items checked in "choose" mode.
 */
export function getSelectedIds() {
  return $(".wpe-pcc-select:checked")
    .map((i, el) => el.value)
    .get();
}

export function updateResult(response, job) {
  const report = response.reports?.phpcs_phpcompatibilitywp?.report;

  // If we have a success response but don't have report data, show an error.
  if (!report) {
    updateResultFailure(
      {
        status: "failed",
        message: __("No scan results found.", "php-compatibility-checker"),
      },
      job
    );
    return;
  }

  const resultItem = $(`#${job.type}_${job.slug}`);
  const template = $("#result-template").html().toString();

  // Data to render the template.
  const view = {
    ...job,
  };

  // Create index for PHP Versions.
  const phpVersions = Object.keys(report.versions).sort(compareVersions);
  let rawReport = `${job.name} ${job.version}\n\n`;

  // Versions below the item's own "Requires PHP" can never run it, so problems
  // there aren't real incompatibilities. Show them as "not counted" instead.
  const requiresPhp = job.requires_php || "";
  const isUnsupported = (phpVersion) =>
    "" !== requiresPhp && compareVersions(phpVersion, requiresPhp) < 0;

  const hasProblems = phpVersions.some((phpVersion) => {
    if (isUnsupported(phpVersion)) {
      return false;
    }
    const totals = report.versions[phpVersion]?.totals || {};
    return (
      report.incompatible.includes(phpVersion) ||
      totals.errors > 0 ||
      totals.warnings > 0
    );
  });

  // If every reported version is below the minimum, nothing it can run on was
  // checked (e.g. it requires PHP 8.3 but results stop at 8.0). Don't show that
  // as a pass.
  if (phpVersions.length && phpVersions.every(isUnsupported)) {
    updateResultFailure(
      {
        status: "skipped",
        message: sprintf(
          /* translators: 1: plugin or theme name, 2: minimum PHP version, 3: newest PHP version with results. */
          __(
            "%1$s was not checked. It requires PHP %2$s or newer, and results only go up to PHP %3$s.",
            "php-compatibility-checker"
          ),
          job.name,
          requiresPhp,
          phpVersions[phpVersions.length - 1]
        ),
      },
      job
    );
    return;
  }

  view.status = hasProblems ? "error" : "success";
  setBadge(view, hasProblems ? "problems" : "success");
  view.has_unsupported = phpVersions.some(isUnsupported);

  view.php = [];
  view.reports = [];

  phpVersions.forEach((phpVersion) => {
    if (isUnsupported(phpVersion)) {
      view.php.push({ phpversion: phpVersion, unsupported: true });
      rawReport += `PHP ${phpVersion}: not counted (requires PHP ${requiresPhp})\n`;
      return;
    }

    view.php.push({
      phpversion: phpVersion,
      passed: report.compatible.includes(phpVersion),
    });

    // Extract all error messages from incompatible PHP versions.
    if (report.incompatible.includes(phpVersion)) {
      const messages = [];
      const files = report.versions[phpVersion].files;
      const fileReports = [];

      // In each file of the plugin, add error messages.
      Object.keys(files).forEach((file) => {
        if (files[file].messages.length) {
          fileReports.push(processFileReport(file, files[file]));

          files[file].messages.forEach((message) => {
            // Compile plain text with source file, line, error code and error text.
            messages.push(
              `${file}:${message.line}\n${message.source}\n${message.message}`
            );
          });
        }
      });

      // Override raw report with most recent PHP version
      const rawVersionReport =
        `PHP ${phpVersion} incompatibilities:\n\n` +
        fileReports.join("\n\n") +
        "\n\n";

      rawReport += rawVersionReport;

      view.reports.push({
        phpversion: phpVersion,
        messages: [rawVersionReport],
      });
    } else {
      rawReport += `Compatible with PHP ${phpVersion}\n`;
    }
  });

  view.has_errors = hasProblems;

  const output = Mustache.render(template, view);

  resultItem.replaceWith(output);

  const fullReport = $("#testResults").val();
  const updatedReport =
    fullReport + (fullReport.length ? "\n\n\n" : "") + rawReport;

  $("#testResults").val(updatedReport);
}

export function processFileReport(fileName, fileReport) {
  const colWidths = fileReport.messages.reduce(
    (prev, current) => {
      return [
        `${current.line}`.length > prev[0] ? `${current.line}`.length : prev[0],
        `${current.type}`.length > prev[1] ? `${current.type}`.length : prev[1],
        `${current.message}`.length > prev[2]
          ? `${current.message}`.length
          : prev[2],
      ];
    },
    [0, 0, 0]
  );

  const uinqueLines = fileReport.messages.reduce((lines, message) => {
    if (-1 === lines.indexOf(message.line)) {
      lines.push(message.line);
    }
    return lines;
  }, []);

  const plainMessages = fileReport.messages.map((message) => {
    const col1 = message.line.toString().padStart(colWidths[0], " ");
    const col2 = message.type.toString().padEnd(colWidths[1], " ");
    const col3 = message.message.toString().padEnd(colWidths[2], " ");

    return ` ${col1} | ${col2} | ${col3} `;
  });

  const header = `FILE: ${fileName}`;
  const found = `FOUND ${fileReport.errors} ERRORS AND ${fileReport.warnings} WARNINGS AFFECTING ${uinqueLines.length} LINES`;
  const maxWidth = Math.max(
    header.length,
    found.length,
    plainMessages[0].length
  );
  const hr = new Array(maxWidth + 1).join("-");
  const output =
    `${header}\n${hr}\n${found}\n${hr}\n` +
    plainMessages.join("\n") +
    `\n${hr}`;

  return output;
}

export function updateResultFailure(response, job) {
  const resultItem = $(`#${job.type}_${job.slug}`);
  const template = $("#result-template").html().toString();
  const skipped = "skipped" === response.status;
  const view = {
    ...job,
    status: skipped ? "skipped" : "error",
    custom_error: true,
    skipped,
    response: response,
  };
  setBadge(view, skipped ? "skipped" : "failed");

  const output = Mustache.render(template, view);
  resultItem.replaceWith(output);

  let rawReport = `${job.name} ${job.version}\n\n`;
  if (response.status) {
    rawReport += `Scan status: ${response.status}\n`;
  }
  if (response.message) {
    rawReport += response.message + "\n";
  }
  if (response.errors) {
    response.errors.forEach((error) => {
      rawReport += error.message + "\n";
    });
  }
  rawReport += "\n";
  const fullReport = $("#testResults").val();
  const updatedReport =
    fullReport + (fullReport.length ? "\n\n\n" : "") + rawReport;

  $("#testResults").val(updatedReport);
}

/**
 * Set the card's status label: success, problems, skipped, or failed.
 */
function setBadge(view, badge) {
  view.badge = badge;
  view[`badge_${badge}`] = true;
}
