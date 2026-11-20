import json
import sys
import math
from decimal import Decimal, ROUND_HALF_UP
from urllib.request import Request, urlopen

from ortools.constraint_solver import (
    pywrapcp,
    routing_enums_pb2,
)


def grams(kg):
    value = Decimal(str(kg))
    result = int(
        (value * 1000).quantize(
            Decimal("1"),
            rounding=ROUND_HALF_UP
        )
    )
    if result <= 0:
        raise ValueError("Weight and capacity must be positive.")
    return result


def coordinate(point):
    lat = float(point["latitude"])
    lng = float(point["longitude"])

    if not (
        math.isfinite(lat) and math.isfinite(lng)
        and -90 <= lat <= 90
        and -180 <= lng <= 180
    ):
        raise ValueError("Invalid GPS coordinates.")

    return f"{lng:.7f},{lat:.7f}"


def road_matrices(points):
    coords = ";".join(coordinate(p) for p in points)

    url = (
        "https://router.project-osrm.org"
        "/table/v1/driving/"
        + coords
        + "?annotations=distance,duration"
    )

    request = Request(
        url,
        headers={
            "User-Agent": "SmartFleet-Development/1.0",
            "Accept": "application/json",
        }
    )

    with urlopen(request, timeout=25) as response:
        result = json.load(response)

    if result.get("code") != "Ok":
        raise ValueError(
            "OSRM: " + str(result.get("message"))
        )

    distances = result.get("distances")
    durations = result.get("durations")
    n = len(points)

    for matrix in (distances, durations):
        if (
            not isinstance(matrix, list)
            or len(matrix) != n
            or any(
                not isinstance(row, list)
                or len(row) != n
                or any(
                    x is None
                    or not math.isfinite(float(x))
                    for x in row
                )
                for row in matrix
            )
        ):
            raise ValueError("Invalid or disconnected road matrix.")

    return (
        [[round(x) for x in row] for row in distances],
        [[math.ceil(x) for x in row] for row in durations],
    )


def optimize(data):
    depot = data["depot"]
    orders = data["orders"]
    vehicles = data["vehicles"]

    if not orders or not vehicles:
        raise ValueError("Orders and vehicles are required.")

    # Keep public OSRM demo usage small
    if len(orders) > 15:
        raise ValueError("Demo limit: maximum 15 orders.")

    if len({o["id"] for o in orders}) != len(orders):
        raise ValueError("Duplicate order IDs.")

    if len({v["id"] for v in vehicles}) != len(vehicles):
        raise ValueError("Duplicate vehicle IDs.")

    points = [depot] + orders

    distances, durations = road_matrices(points)

    demands = [0] + [
        grams(o["weight_kg"]) for o in orders
    ]

    capacities = [
        grams(v["capacity_kg"]) for v in vehicles
    ]

    order_limits = [
        int(v.get("max_orders", len(orders)))
        for v in vehicles
    ]

    if any(limit < 1 for limit in order_limits):
        raise ValueError("Invalid maximum order count.")

    manager = pywrapcp.RoutingIndexManager(
        len(points), len(vehicles), 0
    )

    routing = pywrapcp.RoutingModel(manager)

    def travel_time(a, b):
        return durations[
            manager.IndexToNode(a)
        ][
            manager.IndexToNode(b)
        ]

    time_callback = routing.RegisterTransitCallback(
        travel_time
    )

    routing.SetArcCostEvaluatorOfAllVehicles(
        time_callback
    )

    def demand(index):
        return demands[manager.IndexToNode(index)]

    demand_callback = routing.RegisterUnaryTransitCallback(
        demand
    )

    routing.AddDimensionWithVehicleCapacity(
        demand_callback,
        0,
        capacities,
        True,
        "WeightCapacity"
    )

    def order_count(index):
        return int(manager.IndexToNode(index) != 0)

    count_callback = routing.RegisterUnaryTransitCallback(
        order_count
    )

    routing.AddDimensionWithVehicleCapacity(
        count_callback,
        0,
        order_limits,
        True,
        "OrderCapacity"
    )

    settings = pywrapcp.DefaultRoutingSearchParameters()

    settings.first_solution_strategy = (
        routing_enums_pb2.FirstSolutionStrategy
        .PATH_CHEAPEST_ARC
    )

    settings.local_search_metaheuristic = (
        routing_enums_pb2.LocalSearchMetaheuristic
        .GUIDED_LOCAL_SEARCH
    )

    settings.time_limit.FromSeconds(3)

    solution = routing.SolveWithParameters(settings)

    if solution is None:
        return {
            "success": False,
            "message": "No feasible solution",
            "routes": [],
        }

    results = []
    assigned = []
    total_distance = 0
    total_duration = 0

    for vehicle_index, vehicle in enumerate(vehicles):
        index = routing.Start(vehicle_index)

        order_ids = []
        distance = 0
        duration = 0
        load = 0

        while not routing.IsEnd(index):
            node = manager.IndexToNode(index)

            if node != 0:
                order = orders[node - 1]
                order_ids.append(order["id"])
                assigned.append(order["id"])
                load += demands[node]

            next_index = solution.Value(
                routing.NextVar(index)
            )

            next_node = manager.IndexToNode(next_index)

            distance += distances[node][next_node]
            duration += durations[node][next_node]

            index = next_index

        total_distance += distance
        total_duration += duration

        results.append({
            "vehicle_id": vehicle["id"],
            "order_ids": order_ids,
            "load_kg": load / 1000,
            "distance_meters": distance,
            "duration_seconds": duration,
        })

    expected = sorted(o["id"] for o in orders)

    if sorted(assigned) != expected:
        raise ValueError("Order assignment mismatch.")

    return {
        "success": True,
        "method": "OSRM + OR-Tools",
        "optimization_target": "driving_time",
        "total_distance_meters": total_distance,
        "total_duration_seconds": total_duration,
        "routes": results,
    }


if __name__ == "__main__":
    try:
        payload = json.load(sys.stdin)
        result = optimize(payload)

        print(json.dumps(result, ensure_ascii=False))

    except Exception as error:
        print(json.dumps({
            "success": False,
            "message": str(error),
        }, ensure_ascii=False))
        sys.exit(1)