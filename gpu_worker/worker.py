"""Starter GPU worker: pip install ultralytics opencv-python requests
Reads a camera, detects cars on the GPU, and posts bay status to the PHP API."""
import cv2, time, requests
from ultralytics import YOLO
API, KEY, LOT = "http://localhost:8000/api/index.php", "change-me-now", 1
# Bay rectangles in camera pixels: {bay_number: (x1, y1, x2, y2)}. Measure these once per camera.
BAYS = {1: (40, 20, 90, 100), 2: (95, 20, 145, 100)}
model = YOLO("yolov8s.pt")
cap = cv2.VideoCapture("rtsp://camera-address/stream")  # or a video file
while True:
    ok, frame = cap.read()
    if not ok: time.sleep(1); continue
    t = time.time()
    res = model(frame, device=0, classes=[2, 5, 7], verbose=False)[0]  # car, bus, truck on GPU 0
    cars = [((b[0]+b[2])/2, (b[1]+b[3])/2) for b in res.boxes.xyxy.tolist()]
    bays = {n: "busy" if any(x1<=cx<=x2 and y1<=cy<=y2 for cx, cy in cars) else "free"
            for n, (x1, y1, x2, y2) in BAYS.items()}
    ms = (time.time()-t)*1000
    requests.post(API+"?r=ingest", headers={"X-Ingest-Key": KEY},
                  json={"lot_id": LOT, "bays": bays, "fps": 1000/ms, "latency_ms": ms, "util": 0}, timeout=5)
    time.sleep(0.5)
