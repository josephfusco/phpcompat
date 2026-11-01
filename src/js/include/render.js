import Mustache from "mustache";
import $ from "jquery";
import { compareVersions } from "compare-versions";
import { __, sprintf } from "@wordpress/i18n";

export function initResults(itemsToScan, activeOnly) {
  const container = $("#wpe_pcc_results");
  const template = $("#result-template").html().toString();
  container.empty();

  if (itemsToScan.plugins.length) {
    container.append("<h3>" + __("Plugins", "php-compatibility-checker") + "</h3>");
    itemsToScan.plugins.forEach((plugin) => {
      if ("yes" === plugin.active || "no" === activeOnly) {
        const view = {
          ...plugin,
          type: "plugin",
          status: "pending",
        };

        const output = Mustache.render(template, view);
        container.append(output);
      }
    });
  }

  if (itemsToScan.themes.length) {
    container.append("<h3>" + __("Themes", "php-compatibility-checker") + "</h3>");
    itemsToScan.themes.forEach((theme) => {
      if ("yes" === theme.active || "no" === activeOnly) {
        const view = {
          ...theme,
          type: "theme",
          status: "pending",
        };

        const output = Mustache.render(template, view);
        container.append(output);
      }
    });
  }

  $(".wpe-pcc-results").show();
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
