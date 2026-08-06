const COLORS = {
    forest: "#0B4B34",
    green: "#18784E",
    mint: "#E8F5ED",
    pale: "#F7FAF8",
    gold: "#F5C451",
    ink: "#17221D",
    muted: "#52645B",
    border: "#C9D9D0",
    white: "#FFFFFF",
};

const CURRENCY = '"\u20B1"#,##0.00;[Red]-"\u20B1"#,##0.00';
const INTEGER = "#,##0";

const number = (value) => Number(value || 0);

function formatDate(value, includeTime = false) {
    if (!value) return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        ...(includeTime
            ? { hour: "numeric", minute: "2-digit", hour12: true }
            : {}),
        timeZone: "Asia/Manila",
    }).format(date);
}

function periodDate(value) {
    const [year, month, day] = String(value || "").split("-").map(Number);
    if (!year || !month || !day) return String(value || "");
    return formatDate(new Date(Date.UTC(year, month - 1, day)));
}

function mergedRow(value, columns, options = {}) {
    return [
        { value, columnSpan: columns, ...options },
        ...Array.from({ length: columns - 1 }, () => null),
    ];
}

function headerRow(labels) {
    return labels.map((value) => ({
        value,
        height: 31,
        fontSize: 10,
        fontWeight: "bold",
        textColor: COLORS.white,
        backgroundColor: COLORS.green,
        align: "center",
        alignVertical: "center",
        wrap: true,
        borderColor: COLORS.forest,
        borderStyle: "thin",
    }));
}

function dataCell(value, index, options = {}) {
    return {
        value,
        height: 25,
        alignVertical: "center",
        backgroundColor: index % 2 === 0 ? COLORS.white : COLORS.pale,
        borderColor: COLORS.border,
        borderStyle: "thin",
        ...options,
    };
}

function titleRows(title, subtitle, columns) {
    return [
        mergedRow("NENIAL ENTERPRISES", columns, {
            height: 34,
            fontSize: 18,
            fontWeight: "bold",
            textColor: COLORS.white,
            backgroundColor: COLORS.forest,
            align: "center",
            alignVertical: "center",
        }),
        mergedRow(title, columns, {
            height: 27,
            fontSize: 12,
            fontWeight: "bold",
            textColor: COLORS.forest,
            backgroundColor: COLORS.mint,
            align: "center",
            alignVertical: "center",
        }),
        mergedRow(subtitle, columns, {
            height: 25,
            fontSize: 9,
            textColor: COLORS.muted,
            align: "center",
            alignVertical: "center",
        }),
        Array.from({ length: columns }, () => ({ value: "", height: 7 })),
    ];
}

function commonOptions(sheet, columns, stickyRowsCount = 5) {
    return {
        sheet,
        columns,
        stickyRowsCount,
        showGridLines: false,
        orientation: "landscape",
        zoomScale: 0.9,
        fontFamily: "Aptos",
        fontSize: 10,
    };
}

function emptyRow(message, columns) {
    return mergedRow(message, columns, {
        height: 30,
        fontStyle: "italic",
        textColor: COLORS.muted,
        backgroundColor: COLORS.pale,
        align: "center",
        alignVertical: "center",
        borderColor: COLORS.border,
        borderStyle: "thin",
    });
}

export function buildCompanyReportWorkbook(report, period, filtered = {}) {
    const orders = report.orders || [];
    const attendance = report.attendance || [];
    const payrollRuns = filtered.payrollRuns || report.payroll?.runs || [];
    const inventory = filtered.inventory || report.inventory || [];
    const periodLabel = `${periodDate(period.from)} to ${periodDate(period.to)}`;
    const generatedLabel = `Reporting period: ${periodLabel}  |  Generated ${formatDate(new Date(), true)} PHT`;

    const summaryRows = [
        ...titleRows("COMPANY PERFORMANCE REPORT", generatedLabel, 3),
        headerRow(["Area", "Metric", "Result"]),
        [dataCell("Sales", 0, { fontWeight: "bold" }), dataCell("Gross sales", 0), dataCell(number(report.sales?.total), 0, { format: CURRENCY, align: "right", fontWeight: "bold" })],
        [dataCell("Sales", 1, { fontWeight: "bold" }), dataCell("VATable sales", 1), dataCell(number(report.sales?.vatable_sales), 1, { format: CURRENCY, align: "right" })],
        [dataCell("Sales", 2, { fontWeight: "bold" }), dataCell("VAT collected", 2), dataCell(number(report.sales?.vat_amount), 2, { format: CURRENCY, align: "right" })],
        [dataCell("Sales", 3, { fontWeight: "bold" }), dataCell("Transactions", 3), dataCell(number(report.sales?.count), 3, { format: INTEGER, align: "right" })],
        [dataCell("Orders", 4, { fontWeight: "bold" }), dataCell("Total orders", 4), dataCell(number(report.orders_summary?.count), 4, { format: INTEGER, align: "right" })],
        [dataCell("Orders", 5, { fontWeight: "bold" }), dataCell("Open orders", 5), dataCell(number(report.orders_summary?.pending), 5, { format: INTEGER, align: "right" })],
        [dataCell("Attendance", 6, { fontWeight: "bold" }), dataCell("Attendance records", 6), dataCell(number(report.attendance_summary?.records), 6, { format: INTEGER, align: "right" })],
        [dataCell("Attendance", 7, { fontWeight: "bold" }), dataCell("Employees represented", 7), dataCell(number(report.attendance_summary?.employees), 7, { format: INTEGER, align: "right" })],
        [dataCell("Payroll", 8, { fontWeight: "bold" }), dataCell("Finalized net payroll", 8), dataCell(number(report.payroll?.net_total), 8, { format: CURRENCY, align: "right" })],
        [dataCell("Workforce", 9, { fontWeight: "bold" }), dataCell("Active employees", 9), dataCell(number(report.employees?.active), 9, { format: INTEGER, align: "right" })],
        [dataCell("Inventory", 10, { fontWeight: "bold" }), dataCell("Inventory value", 10), dataCell(number(report.inventory_summary?.value), 10, { format: CURRENCY, align: "right" })],
        [dataCell("Inventory", 11, { fontWeight: "bold" }), dataCell("Low-stock products", 11), dataCell(number(report.inventory_summary?.low_stock), 11, { format: INTEGER, align: "right" })],
    ];

    const orderRows = [
        ...titleRows("ORDER STATISTICS", generatedLabel, 3),
        headerRow(["Status", "Orders", "Value"]),
        ...(orders.length
            ? orders.map((row, index) => [
                dataCell(String(row.status || "Unknown"), index, { fontWeight: "bold" }),
                dataCell(number(row.count), index, { format: INTEGER, align: "right" }),
                dataCell(number(row.total), index, { format: CURRENCY, align: "right" }),
            ])
            : [emptyRow("No orders were recorded in this period.", 3)]),
    ];

    const attendanceRows = [
        ...titleRows("ATTENDANCE STATISTICS", generatedLabel, 2),
        headerRow(["Status", "Records"]),
        ...(attendance.length
            ? attendance.map((row, index) => [
                dataCell(String(row.status || "Unknown"), index, { fontWeight: "bold" }),
                dataCell(number(row.count), index, { format: INTEGER, align: "right" }),
            ])
            : [emptyRow("No attendance was recorded in this period.", 2)]),
    ];

    const payrollRows = [
        ...titleRows("FINALIZED PAYROLL SNAPSHOTS", generatedLabel, 7),
        headerRow(["Reference", "Period start", "Period end", "Employees", "Gross", "Net", "Finalized"]),
        ...(payrollRuns.length
            ? payrollRuns.map((run, index) => [
                dataCell(String(run.reference || ""), index, { fontWeight: "bold", format: "@" }),
                dataCell(periodDate(run.period_start), index),
                dataCell(periodDate(run.period_end), index),
                dataCell(number(run.items_count), index, { format: INTEGER, align: "right" }),
                dataCell(number(run.gross_pay), index, { format: CURRENCY, align: "right" }),
                dataCell(number(run.net_pay), index, { format: CURRENCY, align: "right", fontWeight: "bold", textColor: COLORS.forest }),
                dataCell(formatDate(run.finalized_at, true), index),
            ])
            : [emptyRow("No finalized payroll snapshots match this report.", 7)]),
    ];

    const inventoryRows = [
        ...titleRows("INVENTORY STATISTICS", generatedLabel, 7),
        headerRow(["Product", "SKU", "Category", "On hand", "Reserved", "Available", "Stock value"]),
        ...(inventory.length
            ? inventory.map((product, index) => [
                dataCell(String(product.name || ""), index, { fontWeight: "bold", wrap: true }),
                dataCell(String(product.sku || ""), index, { format: "@" }),
                dataCell(String(product.category || ""), index),
                dataCell(number(product.stock_quantity), index, { format: INTEGER, align: "right" }),
                dataCell(number(product.reserved_quantity), index, { format: INTEGER, align: "right" }),
                dataCell(number(product.available_quantity), index, { format: INTEGER, align: "right", fontWeight: "bold" }),
                dataCell(number(product.stock_quantity) * number(product.price), index, { format: CURRENCY, align: "right", fontWeight: "bold" }),
            ])
            : [emptyRow("No inventory products match this report.", 7)]),
    ];

    return [
        { data: summaryRows, ...commonOptions("Executive Summary", [{ width: 18 }, { width: 31 }, { width: 21 }]) },
        { data: orderRows, ...commonOptions("Orders", [{ width: 22 }, { width: 15 }, { width: 19 }]) },
        { data: attendanceRows, ...commonOptions("Attendance", [{ width: 25 }, { width: 18 }]) },
        { data: payrollRows, ...commonOptions("Payroll Snapshots", [{ width: 26 }, { width: 17 }, { width: 17 }, { width: 14 }, { width: 18 }, { width: 18 }, { width: 24 }]) },
        { data: inventoryRows, ...commonOptions("Inventory", [{ width: 30 }, { width: 17 }, { width: 19 }, { width: 14 }, { width: 14 }, { width: 14 }, { width: 20 }]) },
    ];
}

export async function downloadCompanyReportWorkbook(report, period, filtered = {}) {
    const { default: writeExcelFile } = await import("write-excel-file/browser");
    const sheets = buildCompanyReportWorkbook(report, period, filtered);
    const filename = `nenial-company-report-${period.from}-to-${period.to}.xlsx`;

    await writeExcelFile(sheets).toFile(filename);
}
