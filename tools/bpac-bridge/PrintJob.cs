using System.Text.Json;
using System.Text.Json.Serialization;

namespace PartDb.BpacBridge;

public sealed class PrintJob
{
    public const int CurrentVersion = 1;

    [JsonPropertyName("version")]
    public int Version { get; set; } = CurrentVersion;

    /// <summary>Label length along the tape, matching Part-DB width_mm.</summary>
    [JsonPropertyName("width_mm")]
    public double WidthMm { get; set; } = 30;

    /// <summary>Tape width, matching Part-DB height_mm (12, 18, or 24 for TZe).</summary>
    [JsonPropertyName("height_mm")]
    public double HeightMm { get; set; } = 18;

    /// <summary>stacked puts the name under the QR. beside puts the name to the right.</summary>
    [JsonPropertyName("layout")]
    public string Layout { get; set; } = "stacked";

    [JsonPropertyName("copies")]
    public int Copies { get; set; } = 1;

    [JsonPropertyName("printer")]
    public string? Printer { get; set; }

    [JsonPropertyName("labels")]
    public List<PrintLabel> Labels { get; set; } = [];

    public static PrintJob FromJson(string json)
    {
        var job = JsonSerializer.Deserialize<PrintJob>(json, JsonOptions())
            ?? throw new InvalidOperationException("Print job JSON was empty.");

        if (job.Labels.Count == 0)
        {
            throw new InvalidOperationException("Print job has no labels.");
        }

        if (job.WidthMm <= 0 || job.HeightMm <= 0)
        {
            throw new InvalidOperationException("Print job width_mm and height_mm must be positive.");
        }

        if (job.Copies < 1)
        {
            job.Copies = 1;
        }

        if (!string.Equals(job.Layout, "beside", StringComparison.OrdinalIgnoreCase))
        {
            job.Layout = "stacked";
        }

        return job;
    }

    public string ToJson() => JsonSerializer.Serialize(this, JsonOptions());

    public static JsonSerializerOptions JsonOptions() => new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = false,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };
}

public sealed class PrintLabel
{
    [JsonPropertyName("id")]
    public string? Id { get; set; }

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";

    [JsonPropertyName("description")]
    public string Description { get; set; } = "";

    [JsonPropertyName("qr_url")]
    public string QrUrl { get; set; } = "";
}
