import assert from "node:assert/strict";
import { readFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import writeExcelFile from "write-excel-file/node";
import { buildCompanyReportWorkbook } from "../resources/js/utils/reportWorkbook.js";

const report = {
    sales: { total: 1380, vatable_sales: 1232.14, vat_amount: 147.86, count: 1 },
    orders_summary: { count: 2, pending: 1 },
    attendance_summary: { records: 7, employees: 7 },
    payroll: {
        net_total: 42000,
        runs: [{ reference: "PAY-2026-001", period_start: "2026-08-01", period_end: "2026-08-07", items_count: 7, gross_pay: 47000, net_pay: 42000, finalized_at: "2026-08-07T08:00:00Z" }],
    },
    employees: { active: 7 },
    inventory_summary: { value: 240635, low_stock: 1 },
    orders: [{ status: "completed", count: 1, total: 1380 }],
    attendance: [{ status: "present", count: 7 }],
    inventory: [{ name: "Crushed Gravel", sku: "AGG-002", category: "Aggregates", stock_quantity: 8, reserved_quantity: 1, available_quantity: 7, price: 1380 }],
};

const sheets = buildCompanyReportWorkbook(report, {
    from: "2026-08-01",
    to: "2026-08-07",
});
const output = join(tmpdir(), "nenial-company-report-verification.xlsx");

assert.equal(sheets.length, 5);
assert.deepEqual(sheets.map((sheet) => sheet.sheet), [
    "Executive Summary",
    "Orders",
    "Attendance",
    "Payroll Snapshots",
    "Inventory",
]);
assert.ok(sheets.every((sheet) => sheet.showGridLines === false));
assert.ok(sheets.every((sheet) => sheet.stickyRowsCount === 5));
assert.equal(sheets[0].data[0][0].value, "NENIAL ENTERPRISES");
assert.equal(sheets[3].data[5][0].value, "PAY-2026-001");

await writeExcelFile(sheets).toFile(output);
const file = await readFile(output);
assert.ok(file.length > 8_000);
assert.equal(file.subarray(0, 2).toString(), "PK");

await unlink(output);
console.log("Company report workbook structure and XLSX generation verified.");
