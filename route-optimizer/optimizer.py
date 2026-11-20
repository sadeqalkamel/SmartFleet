from math import radians, sin, cos, sqrt, atan2

from ortools.constraint_solver import (
    pywrapcp,
    routing_enums_pb2,
)

# Test locations: name, latitude, longitude, weight
locations = [
    ("Warehouse", 15.5250, 44.3250, 0),
    ("Order 1", 15.5300, 44.3300, 2),
    ("Order 2", 15.5350, 44.3400, 3),
    ("Order 3", 15.5400, 44.3500, 4),
]

# Two vehicles, each with 5 kg capacity
vehicle_capacities = [5, 5]
num_vehicles = len(vehicle_capacities)
depot = 0


# Calculate straight-line distance in meters
def haversine(lat1, lon1, lat2, lon2):
    earth_radius = 6371000

    lat1, lon1, lat2, lon2 = map(
        radians,
        [lat1, lon1, lat2, lon2]
    )

    dlat = lat2 - lat1
    dlon = lon2 - lon1

    a = (
        sin(dlat / 2) ** 2
        + cos(lat1) * cos(lat2)
        * sin(dlon / 2) ** 2
    )

    return round(
        2 * earth_radius * atan2(
            sqrt(a),
            sqrt(1 - a)
        )
    )


# Build distance matrix
distance_matrix = []

for origin in locations:
    row = []

    for destination in locations:
        distance = haversine(
            origin[1],
            origin[2],
            destination[1],
            destination[2]
        )

        row.append(distance)

    distance_matrix.append(row)


# Shipment weights
demands = [
    int(location[3])
    for location in locations
]


# Initialize OR-Tools routing model
manager = pywrapcp.RoutingIndexManager(
    len(locations),
    num_vehicles,
    depot
)

routing = pywrapcp.RoutingModel(manager)


# Distance callback
def distance_callback(from_index, to_index):
    from_node = manager.IndexToNode(from_index)
    to_node = manager.IndexToNode(to_index)

    return distance_matrix[from_node][to_node]


distance_index = routing.RegisterTransitCallback(
    distance_callback
)

routing.SetArcCostEvaluatorOfAllVehicles(
    distance_index
)


# Weight demand callback
def demand_callback(from_index):
    node = manager.IndexToNode(from_index)
    return demands[node]


demand_index = routing.RegisterUnaryTransitCallback(
    demand_callback
)

# Enforce vehicle capacity limits
routing.AddDimensionWithVehicleCapacity(
    demand_index,
    0,
    vehicle_capacities,
    True,
    "Capacity"
)


# Search configuration
search_parameters = (
    pywrapcp.DefaultRoutingSearchParameters()
)

search_parameters.first_solution_strategy = (
    routing_enums_pb2.FirstSolutionStrategy
    .PATH_CHEAPEST_ARC
)

search_parameters.local_search_metaheuristic = (
    routing_enums_pb2.LocalSearchMetaheuristic
    .GUIDED_LOCAL_SEARCH
)

search_parameters.time_limit.FromSeconds(3)


# Solve VRP
solution = routing.SolveWithParameters(
    search_parameters
)

if solution is None:
    print("NO FEASIBLE SOLUTION FOUND")

else:
    print("\nSMARTFLEET - VRP RESULTS")
    print("=" * 45)

    total_distance = 0
    delivered_orders = []

    for vehicle_id in range(num_vehicles):

        index = routing.Start(vehicle_id)

        route_names = ["Warehouse"]
        route_distance = 0
        route_load = 0

        while not routing.IsEnd(index):

            node = manager.IndexToNode(index)

            if node != depot:
                route_names.append(locations[node][0])
                route_load += demands[node]
                delivered_orders.append(node)

            previous_index = index

            index = solution.Value(
                routing.NextVar(index)
            )

            route_distance += (
                routing.GetArcCostForVehicle(
                    previous_index,
                    index,
                    vehicle_id
                )
            )

        route_names.append("Warehouse")

        total_distance += route_distance

        print(f"\nVehicle {vehicle_id + 1}")
        print(
            f"Capacity: {vehicle_capacities[vehicle_id]} kg"
        )
        print(f"Route: {' -> '.join(route_names)}")
        print(f"Load: {route_load} kg")
        print(f"Distance: {route_distance / 1000:.2f} km")

    print("\n" + "=" * 45)
    print(
        f"Total distance: {total_distance / 1000:.2f} km"
    )
    print(f"Delivered orders: {len(delivered_orders)}")

    assert sorted(delivered_orders) == list(
        range(1, len(locations))
    ), "Some orders are missing or duplicated!"

    print("ALL ORDERS ASSIGNED SUCCESSFULLY")
    print("CAPACITY CONSTRAINTS SATISFIED")