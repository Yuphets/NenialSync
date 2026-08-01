import assert from "node:assert/strict";
import { readFile, unlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import writeExcelFile from "write-excel-file/node";
import { buildPayrollWorkbook } from "../resources/js/utils/payrollWorkbook.js";

const preview = [
    {
        employee: {
            employee_number: "EMP-1001",
            name: "Sample Employee",
            job_title: "Mason",
        },
        calculation: {
            base_pay: 5100,
            incentive: 500,
            overtime_pay: 250,
            gross_pay: 5850,
            sss: 253.85,
            pagibig: 46.15,
            philhealth: 127.5,
            net_pay: 5422.5,
        },
    },
];
const period = {
    period_start: "2026-07-20",
    period_end: "2026-07-26",
};
const workbook = buildPayrollWorkbook(
    preview,
    period,
    new Date("2026-07-26T04:00:00Z"),
);
const output =
    process.env.PAYROLL_VERIFY_OUTPUT ||
    join(tmpdir(), "nenial-payroll-verification.xlsx");

assert.equal(workbook.sheetData.length, 9);
assert.ok(workbook.sheetData.every((row) => row.length === 11));
assert.equal(workbook.sheetData[0][0].value, "NENIAL ENTERPRISES");
assert.equal(workbook.sheetData[5][0].value, "Employee No.");
assert.equal(workbook.sheetData[6][0].value, "EMP-1001");
assert.equal(workbook.sheetData[7][3].value, "=SUM(D7:D7)");
assert.equal(workbook.options.stickyRowsCount, 6);
assert.equal(workbook.options.orientation, "landscape");
assert.equal(workbook.options.showGridLines, false);

await writeExcelFile(workbook.sheetData, workbook.options).toFile(output);
const file = await readFile(output);

assert.ok(file.length > 4_000);
assert.equal(file.subarray(0, 2).toString(), "PK");

if (!process.env.PAYROLL_VERIFY_OUTPUT) {
    await unlink(output);
}
console.log("Payroll workbook structure and XLSX generation verified.");
