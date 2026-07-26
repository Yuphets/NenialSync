const COLUMNS = [
    { width: 16 },
    { width: 25 },
    { width: 22 },
    { width: 15 },
    { width: 15 },
    { width: 16 },
    { width: 16 },
    { width: 14 },
    { width: 14 },
    { width: 15 },
    { width: 16 },
];

const HEADERS = [
    "Employee No.",
    "Employee",
    "Job Title",
    "Base Pay",
    "Incentive",
    "Overtime Pay",
    "Gross Pay",
    "SSS",
    "Pag-IBIG",
    "PhilHealth",
    "Net Pay",
];

const COLORS = {
    forest: "#0B4B34",
    green: "#18784E",
    mint: "#E8F5ED",
    gold: "#F5C451",
    ink: "#17221D",
    muted: "#52645B",
    border: "#C9D9D0",
    white: "#FFFFFF",
};

const currencyFormat = '"₱"#,##0.00;[Red]-"₱"#,##0.00';

function mergedRow(value, options = {}) {
    return [
        { value, columnSpan: 11, ...options },
        ...Array.from({ length: 10 }, () => null),
    ];
}

function summaryCell(value, columnSpan, backgroundColor = COLORS.mint) {
    return {
        value,
        columnSpan,
        height: 32,
        fontWeight: "bold",
        fontSize: 11,
        textColor: COLORS.ink,
        backgroundColor,
        align: "center",
        alignVertical: "center",
        wrap: true,
        borderColor: COLORS.border,
        borderStyle: "thin",
    };
}

function money(value) {
    return Number(value || 0);
}

function formatPeriodDate(value) {
    const [year, month, day] = String(value).split("-").map(Number);
    if (!year || !month || !day) return value;

    return new Intl.DateTimeFormat("en-US", {
        month: "long",
        day: "numeric",
        year: "numeric",
        timeZone: "Asia/Manila",
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

export function buildPayrollWorkbook(preview, period, generatedAt = new Date()) {
    const rows = Array.isArray(preview) ? preview : [];
    const dataStartRow = 7;
    const dataEndRow = dataStartRow + rows.length - 1;
    const sum = (key) =>
        rows.reduce(
            (total, row) => total + money(row.calculation?.[key]),
            0,
        );
    const totalDeductions =
        sum("sss") + sum("pagibig") + sum("philhealth");
    const generated = generatedAt.toLocaleString("en-US", {
        month: "long",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        hour12: true,
        timeZone: "Asia/Manila",
    });
    const periodLabel = `${formatPeriodDate(period.period_start)} – ${formatPeriodDate(period.period_end)}`;
    const summaryNumber = { minimumFractionDigits: 2, maximumFractionDigits: 2 };

    const sheetData = [
        mergedRow("NENIAL ENTERPRISES", {
            height: 34,
            fontSize: 18,
            fontWeight: "bold",
            textColor: COLORS.white,
            backgroundColor: COLORS.forest,
            align: "center",
            alignVertical: "center",
        }),
        mergedRow("WEEKLY PAYROLL REGISTER", {
            height: 26,
            fontSize: 12,
            fontWeight: "bold",
            textColor: COLORS.forest,
            backgroundColor: COLORS.mint,
            align: "center",
            alignVertical: "center",
        }),
        [
            {
                value: `Payroll period: ${periodLabel}`,
                columnSpan: 6,
                height: 28,
                fontWeight: "bold",
                textColor: COLORS.ink,
                alignVertical: "center",
            },
            null,
            null,
            null,
            null,
            null,
            {
                value: `Generated: ${generated} PHT`,
                columnSpan: 5,
                fontSize: 10,
                textColor: COLORS.muted,
                align: "right",
                alignVertical: "center",
            },
            null,
            null,
            null,
            null,
        ],
        [
            summaryCell(
                `${rows.length} employee${rows.length === 1 ? "" : "s"}`,
                2,
            ),
            null,
            summaryCell(
                `Gross payroll\n₱${sum("gross_pay").toLocaleString("en-PH", summaryNumber)}`,
                3,
            ),
            null,
            null,
            summaryCell(
                `Deductions\n₱${totalDeductions.toLocaleString("en-PH", summaryNumber)}`,
                3,
            ),
            null,
            null,
            summaryCell(
                `Net payroll\n₱${sum("net_pay").toLocaleString("en-PH", summaryNumber)}`,
                3,
                "#FFF3CF",
            ),
            null,
            null,
        ],
        Array.from({ length: 11 }, () => ({ value: "", height: 8 })),
        HEADERS.map((value) => ({
            value,
            height: 32,
            fontSize: 10,
            fontWeight: "bold",
            textColor: COLORS.white,
            backgroundColor: COLORS.green,
            align: "center",
            alignVertical: "center",
            wrap: true,
            borderColor: COLORS.forest,
            borderStyle: "thin",
        })),
        ...rows.map((row, index) => {
            const calculation = row.calculation || {};
            const fill = index % 2 === 0 ? COLORS.white : "#F7FAF8";
            const baseStyle = {
                height: 25,
                backgroundColor: fill,
                borderColor: COLORS.border,
                borderStyle: "thin",
                alignVertical: "center",
            };
            const currencyStyle = {
                ...baseStyle,
                format: currencyFormat,
                align: "right",
            };

            return [
                {
                    value: String(row.employee?.employee_number || ""),
                    format: "@",
                    fontWeight: "bold",
                    ...baseStyle,
                },
                { value: row.employee?.name || "", ...baseStyle },
                {
                    value: row.employee?.job_title || "",
                    wrap: true,
                    ...baseStyle,
                },
                { value: money(calculation.base_pay), ...currencyStyle },
                { value: money(calculation.incentive), ...currencyStyle },
                { value: money(calculation.overtime_pay), ...currencyStyle },
                {
                    value: money(calculation.gross_pay),
                    fontWeight: "bold",
                    ...currencyStyle,
                },
                { value: money(calculation.sss), ...currencyStyle },
                { value: money(calculation.pagibig), ...currencyStyle },
                { value: money(calculation.philhealth), ...currencyStyle },
                {
                    value: money(calculation.net_pay),
                    fontWeight: "bold",
                    textColor: COLORS.forest,
                    ...currencyStyle,
                },
            ];
        }),
        [
            {
                value: "TOTALS",
                columnSpan: 3,
                height: 30,
                fontWeight: "bold",
                textColor: COLORS.white,
                backgroundColor: COLORS.forest,
                align: "right",
                alignVertical: "center",
                borderColor: COLORS.forest,
                borderStyle: "thin",
            },
            null,
            null,
            ...Array.from({ length: 8 }, (_, index) => {
                const column = String.fromCharCode("D".charCodeAt(0) + index);
                return {
                    value:
                        rows.length > 0
                            ? `=SUM(${column}${dataStartRow}:${column}${dataEndRow})`
                            : 0,
                    type: rows.length > 0 ? "Formula" : Number,
                    format: currencyFormat,
                    height: 30,
                    fontWeight: "bold",
                    textColor: index === 7 ? COLORS.forest : COLORS.ink,
                    backgroundColor:
                        index === 7 ? "#FFF3CF" : COLORS.mint,
                    align: "right",
                    alignVertical: "center",
                    borderColor: COLORS.forest,
                    borderStyle: "thin",
                };
            }),
        ],
        mergedRow(
            `Prepared from the Nenial workforce payroll preview • ${rows.length} record${rows.length === 1 ? "" : "s"} • Totals are calculated automatically in Excel.`,
            {
                height: 24,
                fontSize: 9,
                fontStyle: "italic",
                textColor: COLORS.muted,
                align: "left",
                alignVertical: "center",
            },
        ),
    ];

    return {
        sheetData,
        options: {
            sheet: "Payroll Register",
            columns: COLUMNS,
            orientation: "landscape",
            stickyRowsCount: 6,
            showGridLines: false,
            zoomScale: 0.9,
            fontFamily: "Aptos",
            fontSize: 10,
        },
    };
}

export async function downloadPayrollWorkbook(preview, period) {
    const { default: writeExcelFile } = await import(
        "write-excel-file/browser"
    );
    const { sheetData, options } = buildPayrollWorkbook(preview, period);
    const filename = `nenial-payroll-${period.period_start}-to-${period.period_end}.xlsx`;

    await writeExcelFile(sheetData, options).toFile(filename);
}
