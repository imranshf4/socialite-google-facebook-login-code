

const ImageAnalyzer = {
  async analyzeImage(img) {
    await loadTagModel();
    const predictions = await tagModel.classify(img);

    const tags = predictions
      .flatMap(p => p.className.split(",").map(t => t.trim().toLowerCase()))
      .filter(Boolean);

    return {
      tags: [...new Set(tags)],
      color_palette: this.extractDetailedColorPalette(img),
      visual_features: this.extractVisualFeatures(img),
      pattern_type: this.detectPattern(img)
    };
  },

  extractDetailedColorPalette(img) {
    try {
      if (!window.colorThief) window.colorThief = new ColorThief();

      const palette = window.colorThief.getPalette(img, 5);

      return palette.map((rgb, index) => {
        const colorName = this.getColorName(rgb[0], rgb[1], rgb[2]);
        return {
          name: colorName,
          rgb: `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`,
          hex: this.rgbToHex(rgb[0], rgb[1], rgb[2]),
          percentage: Math.round(((5 - index) / 15) * 100),
        };
      });
    } catch (error) {
      console.error("Color palette extraction error:", error);
      return [];
    }
  },

  extractVisualFeatures(img) {
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    canvas.width = 200;
    canvas.height = 200;
    ctx.drawImage(img, 0, 0, 200, 200);

    const imageData = ctx.getImageData(0, 0, 200, 200);
    const pixels = imageData.data;

    let totalBrightness = 0;
    let totalSaturation = 0;
    let colorVariance = 0;
    let edgeCount = 0;
    let pixelCount = 0;

    for (let i = 0; i < pixels.length; i += 16) {
      const r = pixels[i], g = pixels[i + 1], b = pixels[i + 2], a = pixels[i + 3];
      if (a < 125) continue;

      const brightness = (r + g + b) / 3;
      totalBrightness += brightness;

      const max = Math.max(r, g, b);
      const min = Math.min(r, g, b);
      const saturation = max === 0 ? 0 : (max - min) / max;
      totalSaturation += saturation;

      const avg = brightness;
      const variance = Math.abs(r - avg) + Math.abs(g - avg) + Math.abs(b - avg);
      colorVariance += variance;

      if (i > 0 && i < pixels.length - 4) {
        const prevBrightness =
          (pixels[i - 4] + pixels[i - 3] + pixels[i - 2]) / 3;
        if (Math.abs(brightness - prevBrightness) > 30) edgeCount++;
      }

      pixelCount++;
    }

    const avgBrightness = totalBrightness / pixelCount;
    const avgSaturation = totalSaturation / pixelCount;
    const avgComplexity = colorVariance / pixelCount;
    const edgeDensity = edgeCount / pixelCount;

    return {
      brightness:
        avgBrightness > 180
          ? "light"
          : avgBrightness > 100
          ? "medium"
          : "dark",
      brightness_score: Math.round(avgBrightness),
      saturation:
        avgSaturation > 0.5
          ? "vibrant"
          : avgSaturation > 0.2
          ? "moderate"
          : "muted",
      saturation_score: Math.round(avgSaturation * 100),
      complexity:
        avgComplexity > 50
          ? "complex"
          : avgComplexity > 25
          ? "moderate"
          : "simple",
      texture:
        edgeDensity > 0.3
          ? "detailed"
          : edgeDensity > 0.15
          ? "normal"
          : "smooth",
    };
  },

  detectPattern(img) {
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    canvas.width = 100;
    canvas.height = 100;
    ctx.drawImage(img, 0, 0, 100, 100);

    const imageData = ctx.getImageData(0, 0, 100, 100);
    const pixels = imageData.data;

    let horizontalPatterns = 0;
    let verticalPatterns = 0;

    for (let y = 0; y < 100; y += 10) {
      let prevColor = null;
      let changes = 0;
      for (let x = 0; x < 100; x += 5) {
        const i = (y * 100 + x) * 4;
        const color = `${pixels[i]},${pixels[i + 1]},${pixels[i + 2]}`;
        if (prevColor && prevColor !== color) changes++;
        prevColor = color;
      }
      if (changes > 3 && changes < 8) horizontalPatterns++;
    }

    for (let x = 0; x < 100; x += 10) {
      let prevColor = null;
      let changes = 0;
      for (let y = 0; y < 100; y += 5) {
        const i = (y * 100 + x) * 4;
        const color = `${pixels[i]},${pixels[i + 1]},${pixels[i + 2]}`;
        if (prevColor && prevColor !== color) changes++;
        prevColor = color;
      }
      if (changes > 3 && changes < 8) verticalPatterns++;
    }

    if (horizontalPatterns > 5) return "striped-horizontal";
    if (verticalPatterns > 5) return "striped-vertical";
    if (horizontalPatterns > 3 && verticalPatterns > 3) return "checkered";
    if (horizontalPatterns < 2 && verticalPatterns < 2) return "solid";
    return "mixed";
  },

  rgbToHex(r, g, b) {
    return (
      "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)
    );
  },

  getColorName(r, g, b) {
    const brightness = (r + g + b) / 3;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const saturation = max === 0 ? 0 : (max - min) / max;

    const rNorm = r / 255,
      gNorm = g / 255,
      bNorm = b / 255;
    const maxNorm = Math.max(rNorm, gNorm, bNorm);
    const minNorm = Math.min(rNorm, gNorm, bNorm);
    const delta = maxNorm - minNorm;

    let hue = 0;
    if (delta !== 0) {
      if (maxNorm === rNorm) hue = 60 * (((gNorm - bNorm) / delta) % 6);
      else if (maxNorm === gNorm) hue = 60 * ((bNorm - rNorm) / delta + 2);
      else hue = 60 * ((rNorm - gNorm) / delta + 4);
    }
    if (hue < 0) hue += 360;

    if (hue >= 10 && hue <= 50) {
      if (brightness < 130 && saturation > 0.15) return "brown";
      if (brightness < 100) return "brown";
    }

    if (hue >= 20 && hue <= 60 && saturation >= 0.1 && saturation < 0.35 && brightness > 130)
      return "tan";

    if (saturation < 0.1) {
      if (brightness > 220) return "white";
      if (brightness < 40) return "black";
      if (brightness > 170) return "light-gray";
      if (brightness > 100) return "gray";
      return "dark-gray";
    }

    if ((hue >= 0 && hue < 15) || hue >= 345) {
      if (brightness < 80) return "maroon";
      return "red";
    }
    if (hue >= 15 && hue < 35) return "orange";
    if (hue >= 35 && hue < 70) {
      if (saturation < 0.4 && brightness < 150) return "olive";
      return "yellow";
    }
    if (hue >= 70 && hue < 150) {
      if (brightness < 80) return "dark-green";
      return "green";
    }
    if (hue >= 150 && hue < 200) return "cyan";
    if (hue >= 200 && hue < 260) {
      if (brightness < 80) return "navy";
      return "blue";
    }
    if (hue >= 260 && hue < 300) return "purple";
    if (hue >= 300 && hue < 330) return "magenta";
    if (hue >= 330 && hue < 345) {
      if (brightness > 200) return "pink";
      return "rose";
    }

    return "unknown";
  },
};

export default ImageAnalyzer;
