// src/lib/certificateGenerator.ts
// High-resolution Vector/Raster PDF Certificate Generator for HexaLearn Onboarding
// Dynamically overlays Employee Full Name, Employee Code, and Completion Date onto the official HexaLearn certificate

import { jsPDF } from "jspdf";
import { apiPost } from "./api";

export interface CertificateOptions {
  name: string;
  empCode?: string;
  dateStr?: string;
  userId?: number;
}

/**
 * Formats any date string into "Month DD, YYYY" (e.g. "November 20, 2026")
 */
export function formatCertificateDate(dateInput?: string): string {
  if (!dateInput) {
    const now = new Date();
    return now.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
  }

  try {
    const d = new Date(dateInput);
    if (!isNaN(d.getTime())) {
      return d.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
    }
  } catch { }

  return dateInput;
}

/**
 * Loads an image from URL into an HTMLImageElement
 */
function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => resolve(img);
    img.onerror = () => {
      // Try fallback paths if main path fails
      if (!src.includes("/onboard/")) {
        const fallback = `/onboard${src}`;
        const img2 = new Image();
        img2.crossOrigin = "anonymous";
        img2.onload = () => resolve(img2);
        img2.onerror = (e) => reject(e);
        img2.src = fallback;
      } else {
        reject(new Error(`Failed to load certificate template from ${src}`));
      }
    };
    img.src = src;
  });
}

/**
 * Generates the high-resolution Certificate canvas and returns its Data URL (PNG)
 * Perfect for live on-screen previews and rendering
 */
export async function generateCertificateDataUrl(options: CertificateOptions): Promise<string> {
  const { name, empCode = "", dateStr = "" } = options;

  const formattedName = (name || "Employee").trim();
  const formattedCode = (empCode || "").trim();
  const formattedDate = formatCertificateDate(dateStr);

  // 1. Resolve template path (prefer V2 Final, with versioning to bust browser cache)
  const templateCandidates = [
    "/assets/Certificate_V2_final.jpg?v=2",
    "/onboard/assets/Certificate_V2_final.jpg?v=2",
    "assets/Certificate_V2_final.jpg?v=2",
    "/assets/certificate_template.png?v=2",
    "/onboard/assets/certificate_template.png?v=2",
    "assets/certificate_template.png?v=2",
  ];

  let templateImg: HTMLImageElement | null = null;
  for (const src of templateCandidates) {
    try {
      templateImg = await loadImage(src);
      if (templateImg) break;
    } catch {
      continue;
    }
  }

  if (!templateImg) {
    throw new Error("Certificate template could not be loaded. Please ensure the template file exists.");
  }

  // 2. High-Resolution Canvas (3508 x 2480 at 300 DPI A4 Landscape)
  const width = templateImg.naturalWidth || 3508;
  const height = templateImg.naturalHeight || 2480;
  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;

  const ctx = canvas.getContext("2d");
  if (!ctx) {
    throw new Error("Unable to create canvas 2D rendering context.");
  }

  // 3. Draw Background Template
  ctx.drawImage(templateImg, 0, 0, width, height);

  // Smooth font rendering
  ctx.imageSmoothingEnabled = true;
  ctx.imageSmoothingQuality = "high";

  const scaleX = width / 3508;
  const scaleY = height / 2480;

  // 4. Draw Employee Name (Centered, Deep Hexa Navy Blue, Italic Bold Serif)
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillStyle = "#143e70"; // HexaLearn Deep Brand Navy Blue
  ctx.font = `italic bold ${Math.round(102 * scaleY)}px "Playfair Display", Georgia, Baskerville, "Times New Roman", serif`;
  ctx.fillText(formattedName, 1918 * scaleX, 1138 * scaleY);

  // 5. Draw Employee ID (Centered beneath the name in Slate 700)
  if (formattedCode) {
    const displayCode = formattedCode.toLowerCase().includes("employee")
      ? formattedCode
      : `Employee ID: ${formattedCode}`;
    ctx.fillStyle = "#334155"; // Slate 700
    ctx.font = `bold ${Math.round(42 * scaleY)}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`;
    ctx.fillText(displayCode, 1917 * scaleX, 1332 * scaleY);
  }

  // 6. Draw Completion Date (Centered under Completion Date heading)
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillStyle = "#0f172a"; // Dark Slate 900
  ctx.font = `bold ${Math.round(50 * scaleY)}px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`;
  ctx.fillText(formattedDate, 2345 * scaleX, 1955 * scaleY);

  // 7. Convert Canvas to High-Quality PNG Data URL
  return canvas.toDataURL("image/png", 1.0);
}

/**
 * Generates and triggers download of the official HexaLearn Certificate PDF
 */
export async function downloadCertificatePdf(options: CertificateOptions): Promise<boolean> {
  const { name, empCode = "", userId } = options;

  const formattedName = (name || "Employee").trim();
  const formattedCode = (empCode || "").trim();

  // 1. Generate high-resolution Certificate image Data URL
  const imgData = await generateCertificateDataUrl(options);

  // 2. Generate Crisp Landscape A4 PDF with jsPDF
  const pdf = new jsPDF({
    orientation: "landscape",
    unit: "mm",
    format: "a4",
    compress: true,
  });

  // A4 Landscape dimensions: 297mm x 210mm
  pdf.addImage(imgData, "PNG", 0, 0, 297, 210, undefined, "FAST");

  // Clean filename: e.g. HexaLearn_Certificate_Saroj_Kumar_Das_HL203.pdf
  const safeName = formattedName.replace(/[^a-zA-Z0-9_-]/g, "_");
  const safeCode = formattedCode ? `_${formattedCode.replace(/[^a-zA-Z0-9_-]/g, "")}` : "";
  const fileName = `HexaLearn_Certificate_${safeName}${safeCode}.pdf`;

  pdf.save(fileName);

  // 3. Asynchronously track the download in the backend
  try {
    apiPost("/moodle/track_certificate_download.php", {
      user_id: userId,
      emp_code: formattedCode,
      emp_name: formattedName,
    }).catch(() => { });
  } catch { }

  return true;
}
