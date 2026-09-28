// Independent native decoder for the browser-rendered isolated fixture QR.
// swift tests/qr-decode.swift /private/tmp/bc-qr-test.XXXX/qr.png .../reference.txt
import Foundation
import Vision

let image = URL(fileURLWithPath: CommandLine.arguments[1])
let expected = try String(contentsOfFile: CommandLine.arguments[2], encoding: .utf8)
let request = VNDetectBarcodesRequest()
request.revision = VNDetectBarcodesRequestRevision3
request.symbologies = [.qr]
try VNImageRequestHandler(url: image).perform([request])
guard let results = request.results, results.count == 1,
      results[0].payloadStringValue == expected else {
    fputs("QR independent decode: FAILED\n", stderr)
    exit(1)
}
print("QR independent decode: 1 passed, 0 failed (Vision decoded exact opaque reference).")
