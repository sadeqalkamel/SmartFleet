import json
import sys
from urllib.request import Request, urlopen

from ortools.constraint_solver import (
    pywrapcp,
    routing_enums_pb2
)

# Test delivery locations
locations = [
    ("Warehouse", 15.5250, 44.3250, 0),
    ("Order 1", 15.5300, 44.3300, 2),
    ("Order 2", 15.5350, 44.3400, 3),
    ("Order 3", 15.5400, 44.3500, 4),
]

vehicle_capacities = [5, 5]
num_vehicles = len(vehicle_capacities)


def get_road_matrices():

    # OSRM uses longitude,latitude
    coordinates = ";".join(
        f"{point[2]},{point[1]}"
        for point in locations
    )

    url = (
        "https://router.project-osrm.org"
        "/table/v1/driving/"
        + coordinates
        + "?annotations=distance,duration"
    )

    request = Request(
        url,
        headers={
            "User-Agent": "SmartFleet-Development/1.0",
            "Accept": "application/json"
        }
    )

    with urlopen(request, timeout=25) as response:
        data = json.load(response)

    if data.get("code") != "Ok":
        raise RuntimeError(
            "OSRM error: " + str(data.get("message"))
        )

    distances = data.get("distances")
    durations = data.get("durations")

    n = len(locations)

    if (
        not isinstance(distances, list)
        or not isinstance(durations, list)
        or len(distances) != n
        or len(durations) != n
    ):
        raise RuntimeError("Invalid OSRM matrices.")

    for matrix in (distances, durations):
        if any(
            not isinstance(row, list)
            or len(row) != n
            or any(value is None for value in row)
            for row in matrix
        ):
            raise RuntimeError(
                "Some locations are not connected by roads."
            )

    return (
        [[round(v) for v in row] for row in distances],
        [[round(v) for v in row] for row in durations]
    )


def optimize():

    print("Connecting to OSRM...", file=sys.stderr)

    distance_matrix, time_matrix = get_road_matrices()

    print("ROAD MATRICES LOADED", file=sys.stderr)

    manager = pywrapcp.RoutingIndexManager(
        len(locations),
        num_vehicles,
        0
    )

    routing = pywrapcp.RoutingModel(manager)

    # Optimize estimated travel time
    def time_callback(from_index, to_index):
        a = manager.IndexToNode(from_index)
        b = manager.IndexToNode(to_index)
        return time_matrix[a][b]

    time_index = routing.RegisterTransitCallback(
        time_callback
    )

    routing.SetArcCostEvaluatorOfAllVehicles(
        time_index
    )

    # Vehicle capacity constraints
    demands = [point[3] for point in locations]

    def demand_callback(index):
        return demands[manager.IndexToNode(index)]

    demand_index = routing.RegisterUnaryTransitCallback(
        demand_callback
    )

    routing.AddDimensionWithVehicleCapacity(
        demand_index,
        0,
        vehicle_capacities,
        True,
        "Capacity"
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
        print("NO FEASIBLE SOLUTION")
        return

    total_distance = 0
    total_time = 0
    delivered = []

    print("\nSMARTFLEET - REAL ROAD VRP")
    print("=" * 45)

    for vehicle_id in range(num_vehicles):

        index = routing.Start(vehicle_id)
        names = ["Warehouse"]
        load = 0
        distance = 0
        duration = 0

        while not routing.IsEnd(index):

            node = manager.IndexToNode(index)

            if node != 0:
                names.append(locations[node][0])
                load += demands[node]
                delivered.append(node)

            next_index = solution.Value(
                routing.NextVar(index)
            )

            next_node = manager.IndexToNode(next_index)

            distance += distance_matrix[node][next_node]
            duration += time_matrix[node][next_node]

            index = next_index

        names.append("Warehouse")

        total_distance += distance
        total_time += duration

        print(f"\nVehicle {vehicle_id + 1}")
        print("Route:", " -> ".join(names))
        print(f"Load: {load} kg")
        print(f"Road distance: {distance / 1000:.2f} km")
        print(f"Drive time: {duration / 60:.1f} min")

    print("\n" + "=" * 45)

    print(f"TOTAL ROAD DISTANCE: {total_distance / 1000:.2f} km")
    print(f"TOTAL DRIVE TIME: {total_time / 60:.1f} min")

    if sorted(delivered) == list(range(1, len(locations))):
        print("ALL ORDERS ASSIGNED SUCCESSFULLY")
        print("CAPACITY CONSTRAINTS SATISFIED")


if __name__ == "__main__":
    try:
        optimize()
    except Exception as error:
        print("OSRM/VRP ERROR:", error)
        sys.exit(1)